import argparse
import json
from pathlib import Path


def transcribe_with_faster_whisper(args):
    from faster_whisper import WhisperModel

    model = WhisperModel(args.model, device=args.device, compute_type=args.compute_type)
    segments, info = model.transcribe(
        args.file,
        language=args.language,
        vad_filter=True,
    )

    normalized_segments = [
        {"start": segment.start, "end": segment.end, "text": segment.text.strip()}
        for segment in segments
    ]

    return {
        "engine": "faster-whisper",
        "model": args.model,
        "language": info.language,
        "duration": getattr(info, "duration", None) or duration_from_segments(normalized_segments),
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
