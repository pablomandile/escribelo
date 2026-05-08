import argparse
import json
import sys
from pathlib import Path


def emit_progress(value):
    value = max(0, min(100, int(value)))
    sys.stdout.write(json.dumps({"progress": value}) + "\n")
    sys.stdout.flush()


def transcribe_with_faster_whisper(args):
    from faster_whisper import WhisperModel

    model = WhisperModel(args.model, device=args.device, compute_type=args.compute_type)
    emit_progress(1)
    segments_iter, info = model.transcribe(
        args.file,
        language=args.language,
        vad_filter=True,
    )

    total_duration = getattr(info, "duration", None) or 0
    normalized_segments = []
    last_progress = 1

    for segment in segments_iter:
        normalized_segments.append({
            "start": segment.start,
            "end": segment.end,
            "text": segment.text.strip(),
        })
        if total_duration > 0:
            progress = int((segment.end / total_duration) * 99)
            if progress > last_progress:
                emit_progress(progress)
                last_progress = progress

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
    import whisper

    model = whisper.load_model(args.model)
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

    return parser.parse_args()


def main():
    args = parse_args()

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


if __name__ == "__main__":
    main()
