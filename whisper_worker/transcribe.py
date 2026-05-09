import argparse
import glob
import json
import os
import sys
import tempfile
from pathlib import Path


# Python 3.8+ on Windows ignores PATH for ctypes/DLL resolution by default.
# Register CUDA bin dirs explicitly so faster-whisper / ctranslate2 can find cublas64_12.dll.
if os.name == "nt":
    _cuda_dirs = []
    _cuda_path = os.environ.get("CUDA_PATH")
    if _cuda_path:
        _cuda_dirs.append(os.path.join(_cuda_path, "bin"))
    _cuda_dirs.extend(glob.glob(r"C:\Program Files\NVIDIA GPU Computing Toolkit\CUDA\v*\bin"))
    for _d in _cuda_dirs:
        if os.path.isdir(_d):
            try:
                os.add_dll_directory(_d)
            except OSError:
                pass


# ---------------------------------------------------------------------------
# Event emission. The CLI prints NDJSON to stdout. The FastAPI server (api_server.py)
# imports the worker functions and passes its own callback to capture events.
# ---------------------------------------------------------------------------

# En Windows + CUDA, el destructor del WhisperModel/ctranslate2 puede segfaultear
# al liberar el contexto de GPU cuando termina la función que tenía la referencia.
# Mantenemos referencias vivas a nivel de módulo para evitar el decref hasta que
# el proceso termine vía os._exit(0) (que saltea destructores).
_KEEP_ALIVE = []


def stdout_emit(payload):
    sys.stdout.write(json.dumps(payload) + "\n")
    sys.stdout.flush()


def make_progress_emitter(emit_event):
    def emit_progress(value):
        value = max(0, min(100, int(value)))
        emit_event({"progress": value})

    return emit_progress


def make_phase_emitter(emit_event):
    def emit_phase(phase, **extra):
        emit_event({"phase": phase, **extra})

    return emit_phase


# ---------------------------------------------------------------------------
# Transcription engines
# ---------------------------------------------------------------------------

def detect_gpu():
    """Returns dict with GPU info for logging. Uses ctranslate2 (faster-whisper's backend)
    rather than torch, since torch CUDA isn't required for faster-whisper to use GPU."""
    info = {"available": False, "device_count": 0, "name": None}
    try:
        import ctranslate2
        count = ctranslate2.get_cuda_device_count()
        info["device_count"] = count
        info["available"] = count > 0
        if count > 0:
            try:
                # Best-effort GPU name via subprocess to nvidia-smi.
                import subprocess
                result = subprocess.run(
                    ["nvidia-smi", "--query-gpu=name", "--format=csv,noheader"],
                    capture_output=True, text=True, timeout=2,
                )
                if result.returncode == 0:
                    info["name"] = result.stdout.strip().splitlines()[0]
            except Exception:
                pass
    except Exception:
        pass
    return info


def transcribe_with_faster_whisper(file_path, model_name, language, emit_event):
    emit_phase = make_phase_emitter(emit_event)
    emit_progress = make_progress_emitter(emit_event)

    emit_phase("loading_engine", engine="faster-whisper", model=model_name)
    from faster_whisper import WhisperModel

    gpu = detect_gpu()
    # Ampere+ GPUs (RTX 30xx, 40xx) corren float16 nativo; ~4x más rápido que float32.
    # CPU default int8 para velocidad; float32 si querés máxima calidad (más lento).

    model = None
    device = "cpu"
    compute_type = "int8"
    fallback_reason = None

    if gpu["available"]:
        try:
            emit_phase(
                "loading_model",
                model=model_name,
                device="cuda",
                compute_type="float16",
                gpu_name=gpu.get("name"),
            )
            model = WhisperModel(model_name, device="cuda", compute_type="float16")
            device = "cuda"
            compute_type = "float16"
        except Exception as exc:
            # Caso típico: cublas64_12.dll faltante porque hay driver pero no CUDA Toolkit.
            # En vez de crashear, caemos a CPU y logueamos el motivo.
            fallback_reason = str(exc)
            emit_phase(
                "gpu_fallback",
                reason=fallback_reason[:200],
                next_device="cpu",
                hint="Instalá CUDA Toolkit 12.x desde NVIDIA para activar GPU.",
            )

    if model is None:
        emit_phase("loading_model", model=model_name, device="cpu", compute_type="int8")
        model = WhisperModel(model_name, device="cpu", compute_type="int8")

    # Mantener viva la ref al modelo hasta que el proceso termine — ver _KEEP_ALIVE.
    _KEEP_ALIVE.append(model)

    emit_progress(1)

    emit_phase("starting_transcription", file=file_path, language=language)
    segments_iter, info = model.transcribe(
        file_path,
        language=language,
        vad_filter=True,
    )

    total_duration = getattr(info, "duration", None) or 0
    detected_language = getattr(info, "language", None)
    emit_phase(
        "audio_analyzed",
        duration_seconds=total_duration,
        detected_language=detected_language,
    )

    normalized_segments = []
    last_progress = 1

    for segment in segments_iter:
        normalized_segments.append({
            "start": segment.start,
            "end": segment.end,
            "text": segment.text.strip(),
        })
        if total_duration > 0:
            progress = max(1, int((segment.end / total_duration) * 99))
            if progress > last_progress:
                emit_progress(progress)
                last_progress = progress

    emit_phase("finalizing", segments=len(normalized_segments))
    emit_progress(100)

    return {
        "engine": "faster-whisper",
        "model": model_name,
        "language": info.language,
        "duration": total_duration or duration_from_segments(normalized_segments),
        "text": " ".join(segment["text"] for segment in normalized_segments).strip(),
        "segments": normalized_segments,
    }


def transcribe_with_openai_whisper(file_path, model_name, language, emit_event):
    emit_phase = make_phase_emitter(emit_event)
    emit_progress = make_progress_emitter(emit_event)

    emit_phase("loading_engine", engine="openai-whisper", model=model_name)
    import whisper

    emit_phase("loading_model", model=model_name)
    model = whisper.load_model(model_name)

    emit_phase("starting_transcription", file=file_path, language=language,
               note="openai-whisper does not emit intermediate progress")
    result = model.transcribe(
        file_path,
        language=language,
        verbose=False,
    )

    normalized_segments = [
        {
            "start": float(segment.get("start", 0)),
            "end": float(segment.get("end", 0)),
            "text": str(segment.get("text", "")).strip(),
        }
        for segment in result.get("segments", [])
    ]

    emit_progress(100)

    return {
        "engine": "openai-whisper",
        "model": model_name,
        "language": result.get("language"),
        "duration": duration_from_segments(normalized_segments),
        "text": result.get("text", "").strip(),
        "segments": normalized_segments,
    }


def transcribe_with_best_engine(file_path, model_name, language, emit_event):
    """Try faster-whisper first, fall back to openai-whisper."""
    try:
        return transcribe_with_faster_whisper(file_path, model_name, language, emit_event)
    except ModuleNotFoundError:
        return transcribe_with_openai_whisper(file_path, model_name, language, emit_event)


def duration_from_segments(segments):
    if not segments:
        return None

    return float(segments[-1]["end"])


# ---------------------------------------------------------------------------
# Denoise (ffmpeg + RNNoise)
# ---------------------------------------------------------------------------

RNNOISE_MODEL_PATH = str(Path(__file__).parent / "models" / "cb.rnnn")


def denoise_with_ffmpeg(input_path, mp3_output, emit_event):
    """Denoise audio with ffmpeg's arnndn filter (RNNoise). Returns the temp WAV path used for transcription.
    If mp3_output is provided, also encode an MP3 copy to that path."""
    import subprocess

    emit_phase = make_phase_emitter(emit_event)

    emit_phase("denoise_loading", engine="ffmpeg-arnndn", model=RNNOISE_MODEL_PATH)

    if not os.path.isfile(RNNOISE_MODEL_PATH):
        raise FileNotFoundError(
            f"RNNoise model not found at {RNNOISE_MODEL_PATH}. "
            "Re-clone the repo or download it from https://github.com/GregorR/rnnoise-models."
        )

    temp_dir = tempfile.mkdtemp(prefix="escribelo_denoise_")
    wav_path = os.path.join(temp_dir, "cleaned.wav")

    # ffmpeg has 2 levels of parsing (filtergraph + filter args). The ':' from drive
    # letter ('C:') has to be escaped with DOUBLE backslash → '\\:'.
    arnndn_path = RNNOISE_MODEL_PATH.replace("\\", "/").replace(":", r"\\:")

    emit_phase("denoise_processing", file=input_path, model=os.path.basename(RNNOISE_MODEL_PATH))
    subprocess.run(
        ["ffmpeg", "-y", "-loglevel", "error",
         "-i", input_path,
         "-af", f"arnndn=m={arnndn_path}",
         "-ar", "16000", "-ac", "1",
         wav_path],
        check=True,
    )

    if mp3_output:
        os.makedirs(os.path.dirname(mp3_output), exist_ok=True)
        emit_phase("denoise_encoding_mp3", output=mp3_output)
        subprocess.run(
            ["ffmpeg", "-y", "-loglevel", "error",
             "-i", input_path,
             "-af", f"arnndn=m={arnndn_path}",
             "-codec:a", "libmp3lame", "-b:a", "192k",
             mp3_output],
            check=True,
        )

    emit_phase("denoise_done", wav=wav_path, mp3=mp3_output)
    return wav_path


# ---------------------------------------------------------------------------
# Public entry-point used by both CLI and FastAPI server
# ---------------------------------------------------------------------------

def run_pipeline(file_path, model_name, language, clean_audio, cleaned_output, emit_event):
    """End-to-end: optional denoise → transcription. Returns the final result dict."""
    cleaned_temp_wav = None
    try:
        if clean_audio:
            cleaned_temp_wav = denoise_with_ffmpeg(file_path, cleaned_output, emit_event)
            file_path = cleaned_temp_wav

        return transcribe_with_best_engine(file_path, model_name, language, emit_event)
    finally:
        if cleaned_temp_wav:
            try:
                os.remove(cleaned_temp_wav)
                os.rmdir(os.path.dirname(cleaned_temp_wav))
            except OSError:
                pass


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args():
    parser = argparse.ArgumentParser(description="Transcribe an audio file with a local Whisper installation.")
    parser.add_argument("--file", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", default="small")
    parser.add_argument("--language")
    parser.add_argument("--device", default="auto")
    parser.add_argument("--compute-type", default="default")
    parser.add_argument("--clean-audio", action="store_true",
                        help="Run ffmpeg arnndn (RNNoise) noise reduction before transcribing.")
    parser.add_argument("--cleaned-output",
                        help="If --clean-audio, also encode the cleaned audio to this MP3 path (192kbps).")

    return parser.parse_args()


def main():
    args = parse_args()

    try:
        payload = run_pipeline(
            file_path=args.file,
            model_name=args.model,
            language=args.language,
            clean_audio=args.clean_audio,
            cleaned_output=args.cleaned_output,
            emit_event=stdout_emit,
        )

        output_path = Path(args.output)
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        stdout_emit({"phase": "saved", "output": str(output_path)})
        sys.stdout.flush()
        sys.stderr.flush()

        # En Windows + CUDA, dejar que el intérprete de Python haga su shutdown normal
        # a veces segfaultea al liberar el contexto de GPU (problema conocido con
        # ctranslate2/cuDNN). Como ya escribimos el JSON, forzamos salida limpia
        # saltando los destructores estáticos.
        os._exit(0)
    except Exception as exc:
        sys.stderr.write(f"transcribe.py failed: {type(exc).__name__}: {exc}\n")
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.stderr.flush()
        os._exit(1)


if __name__ == "__main__":
    main()
