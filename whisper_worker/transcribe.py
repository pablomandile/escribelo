import argparse
import json
import os
import sys
import tempfile
from pathlib import Path


def emit_event(payload):
    sys.stdout.write(json.dumps(payload) + "\n")
    sys.stdout.flush()


def emit_progress(value):
    value = max(0, min(100, int(value)))
    emit_event({"progress": value})


def emit_phase(phase, **extra):
    emit_event({"phase": phase, **extra})


def transcribe_with_faster_whisper(args):
    emit_phase("loading_engine", engine="faster-whisper", model=args.model)
    from faster_whisper import WhisperModel

    emit_phase("loading_model", model=args.model, device=args.device)
    model = WhisperModel(args.model, device=args.device, compute_type=args.compute_type)
    emit_progress(1)

    emit_phase("starting_transcription", file=args.file, language=args.language)
    segments_iter, info = model.transcribe(
        args.file,
        language=args.language,
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
        "model": args.model,
        "language": info.language,
        "duration": total_duration or duration_from_segments(normalized_segments),
        "text": " ".join(segment["text"] for segment in normalized_segments).strip(),
        "segments": normalized_segments,
    }


def transcribe_with_openai_whisper(args):
    emit_phase("loading_engine", engine="openai-whisper", model=args.model)
    import whisper

    emit_phase("loading_model", model=args.model)
    model = whisper.load_model(args.model)

    emit_phase("starting_transcription", file=args.file, language=args.language, note="openai-whisper does not emit intermediate progress")
    result = model.transcribe(
        args.file,
        language=args.language,
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
        "model": args.model,
        "language": result.get("language"),
        "duration": duration_from_segments(normalized_segments),
        "text": result.get("text", "").strip(),
        "segments": normalized_segments,
    }


def duration_from_segments(segments):
    if not segments:
        return None

    return float(segments[-1]["end"])


def parse_args():
    parser = argparse.ArgumentParser(description="Transcribe an audio file with a local Whisper installation.")
    parser.add_argument("--file", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", default="small")
    parser.add_argument("--language")
    parser.add_argument("--device", default="auto")
    parser.add_argument("--compute-type", default="default")
    parser.add_argument("--clean-audio", action="store_true",
                        help="Run DeepFilterNet noise reduction before transcribing.")
    parser.add_argument("--cleaned-output",
                        help="If --clean-audio, also encode the cleaned audio to this MP3 path (192kbps).")

    return parser.parse_args()


def denoise_with_deepfilternet(input_path, mp3_output=None):
    """Denoise audio with DeepFilterNet. Returns the temp WAV path used for transcription.
    If mp3_output is provided, also encode an MP3 copy to that path."""
    import subprocess

    emit_phase("denoise_loading", engine="deepfilternet")
    from df.enhance import enhance, init_df, load_audio, save_audio

    model, df_state, _ = init_df()
    emit_phase("denoise_processing", file=input_path, sample_rate=df_state.sr())

    audio, _ = load_audio(input_path, sr=df_state.sr())
    enhanced = enhance(model, df_state, audio)

    temp_dir = tempfile.mkdtemp(prefix="escribelo_denoise_")
    wav_path = os.path.join(temp_dir, "cleaned.wav")
    save_audio(wav_path, enhanced, df_state.sr())

    if mp3_output:
        os.makedirs(os.path.dirname(mp3_output), exist_ok=True)
        emit_phase("denoise_encoding_mp3", output=mp3_output)
        subprocess.run(
            ["ffmpeg", "-y", "-loglevel", "error", "-i", wav_path,
             "-codec:a", "libmp3lame", "-b:a", "192k", mp3_output],
            check=True,
        )

    emit_phase("denoise_done", wav=wav_path, mp3=mp3_output)
    return wav_path


def main():
    args = parse_args()
    cleaned_path = None

    try:
        if args.clean_audio:
            cleaned_path = denoise_with_deepfilternet(args.file, mp3_output=args.cleaned_output)
            args.file = cleaned_path

        try:
            payload = transcribe_with_faster_whisper(args)
        except ModuleNotFoundError:
            payload = transcribe_with_openai_whisper(args)

        output_path = Path(args.output)
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    finally:
        if cleaned_path:
            try:
                os.remove(cleaned_path)
                os.rmdir(os.path.dirname(cleaned_path))
            except OSError:
                pass


if __name__ == "__main__":
    main()
