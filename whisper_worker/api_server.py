"""
FastAPI wrapper exposing local Whisper + Ollama as HTTP endpoints.
Designed to run on a GPU-equipped PC and be reached by the hosted Escríbelo
through a Cloudflare Tunnel.

Endpoints (all require Authorization: Bearer ${ESCRIBELO_REMOTE_TOKEN}):

    GET  /health          → { status, whisper, ollama }
    POST /transcribe      → multipart (file, model, language, clean_audio)
                            returns NDJSON streaming response
    POST /summarize       → JSON { text, language, model }
                            returns JSON { summary, key_points, tokens_used, model }

Run with:
    set ESCRIBELO_REMOTE_TOKEN=...
    uvicorn whisper_worker.api_server:app --host 127.0.0.1 --port 8765
"""

import asyncio
import base64
import contextlib
import json
import os
import re
import tempfile
from typing import Optional

import httpx
from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import JSONResponse, StreamingResponse

# Importamos las funciones del worker que ya hace todo el trabajo pesado.
from whisper_worker import transcribe as worker


@contextlib.asynccontextmanager
async def lifespan(app: FastAPI):
    pid_file = os.environ.get("ESCRIBELO_PID_FILE")
    if pid_file:
        try:
            os.makedirs(os.path.dirname(pid_file), exist_ok=True)
            with open(pid_file, "w") as f:
                f.write(str(os.getpid()))
        except OSError:
            pass
    try:
        yield
    finally:
        if pid_file and os.path.exists(pid_file):
            try:
                os.remove(pid_file)
            except OSError:
                pass


app = FastAPI(title="Escríbelo Remote Worker", version="1.0.0", lifespan=lifespan)


def _check_token(authorization: Optional[str]) -> None:
    expected = os.environ.get("ESCRIBELO_REMOTE_TOKEN")
    if not expected:
        # Fail closed — rather refuse than serve unauth.
        raise HTTPException(status_code=500, detail="ESCRIBELO_REMOTE_TOKEN not configured")

    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token")

    token = authorization[7:].strip()
    if token != expected:
        raise HTTPException(status_code=401, detail="Invalid token")


# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

@app.get("/health")
async def health(authorization: Optional[str] = Header(None)) -> JSONResponse:
    _check_token(authorization)

    gpu = worker.detect_gpu()
    payload = {
        "status": "ok",
        "whisper": "ready",
        "gpu": gpu,
        "compute_type": "float16" if gpu["available"] else "int8",
    }

    # Best-effort Ollama probe.
    try:
        async with httpx.AsyncClient(timeout=2.0) as client:
            r = await client.get(_ollama_url("/api/tags"))
            payload["ollama"] = "reachable" if r.status_code == 200 else f"http_{r.status_code}"
    except Exception:
        payload["ollama"] = "unreachable"

    return JSONResponse(payload)


# ---------------------------------------------------------------------------
# Transcribe — streams NDJSON events. The last line carries `result` and
# (optionally) `cleaned_audio_b64`.
# ---------------------------------------------------------------------------

@app.post("/transcribe")
async def transcribe(
    file: UploadFile = File(...),
    model: str = Form("small"),
    language: Optional[str] = Form(None),
    clean_audio: str = Form("0"),
    authorization: Optional[str] = Header(None),
):
    _check_token(authorization)

    clean = clean_audio in ("1", "true", "True", "yes")

    # Persist the uploaded audio to a temp file (streaming the upload to disk).
    suffix = os.path.splitext(file.filename or "audio.bin")[1] or ".bin"
    fd, tmp_audio = tempfile.mkstemp(prefix="escribelo_in_", suffix=suffix)
    os.close(fd)
    with open(tmp_audio, "wb") as out:
        while True:
            chunk = await file.read(1024 * 1024)
            if not chunk:
                break
            out.write(chunk)

    cleaned_mp3 = None
    if clean:
        cleaned_fd, cleaned_mp3 = tempfile.mkstemp(prefix="escribelo_cleaned_", suffix=".mp3")
        os.close(cleaned_fd)

    queue: asyncio.Queue = asyncio.Queue()

    def emit_event(payload: dict) -> None:
        # Called from worker threads — push to the asyncio queue safely.
        loop.call_soon_threadsafe(queue.put_nowait, payload)

    async def run_pipeline_in_thread():
        return await asyncio.to_thread(
            worker.run_pipeline,
            tmp_audio,
            model,
            language,
            clean,
            cleaned_mp3,
            emit_event,
        )

    loop = asyncio.get_running_loop()
    task = asyncio.create_task(run_pipeline_in_thread())

    async def event_stream():
        try:
            while True:
                # Wait either for an event or for the pipeline task to finish.
                getter = asyncio.create_task(queue.get())
                done, _ = await asyncio.wait(
                    {getter, task},
                    return_when=asyncio.FIRST_COMPLETED,
                )

                if getter in done:
                    payload = getter.result()
                    yield json.dumps(payload, ensure_ascii=False) + "\n"
                else:
                    getter.cancel()

                if task.done() and queue.empty():
                    break

            # Pipeline finished — surface result or error.
            try:
                result = task.result()
            except Exception as exc:
                yield json.dumps({"error": str(exc)}, ensure_ascii=False) + "\n"
                return

            final = {"result": result}
            if cleaned_mp3 and os.path.isfile(cleaned_mp3):
                with open(cleaned_mp3, "rb") as f:
                    final["cleaned_audio_b64"] = base64.b64encode(f.read()).decode("ascii")
            yield json.dumps(final, ensure_ascii=False) + "\n"
        finally:
            # Cleanup temp files.
            for path in (tmp_audio, cleaned_mp3):
                if path and os.path.exists(path):
                    try:
                        os.remove(path)
                    except OSError:
                        pass

    return StreamingResponse(event_stream(), media_type="application/x-ndjson")


# ---------------------------------------------------------------------------
# Summarize — proxies to local Ollama, expects same contract as
# OllamaSummarizer in the Laravel app.
# ---------------------------------------------------------------------------

SUMMARY_SYSTEM_PROMPT = (
    "Sos un asistente que resume transcripciones de audio en español, en tercera persona y neutral. "
    "Devolvé EXCLUSIVAMENTE JSON válido con la forma "
    '{"summary": string, "key_points": string[]}. '
    '"summary": un resumen claro de 4 a 8 oraciones. '
    '"key_points": entre 6 y 12 elementos, cada uno una frase CORTA e independiente '
    "(máximo 25 palabras) sobre un punto concreto. NO juntes varias ideas en un mismo ítem "
    "y NO repitas frases. No agregues nada de texto fuera del JSON."
)


def _parse_summary_json(content: str):
    """Rescata el JSON del modelo aunque venga con texto extra o algo malformado
    (gemma a veces mete un prefacio o cierra mal el JSON en textos largos/repetitivos)."""
    content = (content or "").strip()
    if not content:
        return None
    try:
        obj = json.loads(content)
        if isinstance(obj, dict):
            return obj
    except json.JSONDecodeError:
        pass
    match = re.search(r"\{[\s\S]*\}", content)
    if match:
        try:
            obj = json.loads(match.group(0))
            if isinstance(obj, dict):
                return obj
        except json.JSONDecodeError:
            pass
    return None


@app.post("/summarize")
async def summarize(payload: dict, authorization: Optional[str] = Header(None)) -> StreamingResponse:
    _check_token(authorization)

    text = (payload.get("text") or "").strip()
    if not text:
        raise HTTPException(status_code=400, detail="Missing text")

    model = os.environ.get("OLLAMA_SUMMARY_MODEL", "gemma3:12b")

    body = {
        "model": model,
        "messages": [
            {"role": "system", "content": SUMMARY_SYSTEM_PROMPT},
            {"role": "user", "content": "Transcripción:\n\n" + text},
        ],
        "stream": False,
        "format": "json",
        "options": {
            # Mismo criterio que OllamaSummarizer local: repeat_penalty saca al
            # modelo de los loops de repetición (lo que arruinaba el JSON en textos
            # largos/repetitivos), num_ctx evita el truncado silencioso del input y
            # num_predict deja espacio para cerrar el JSON completo.
            "temperature": 0.5,
            "repeat_penalty": 1.5,
            "top_p": 0.9,
            "top_k": 40,
            "num_predict": 4096,
            "num_ctx": 12288,
        },
        "keep_alive": "30m",
    }

    async def call_ollama() -> dict:
        try:
            async with httpx.AsyncClient(timeout=600.0) as client:
                r = await client.post(_ollama_url("/api/chat"), json=body)
        except Exception as exc:
            raise RuntimeError(f"Ollama unreachable: {exc}")

        if r.status_code != 200:
            raise RuntimeError(f"Ollama HTTP {r.status_code}: {r.text}")

        data = r.json()
        content = (data.get("message") or {}).get("content") or ""

        parsed = _parse_summary_json(content)
        if parsed is None:
            raise RuntimeError("Ollama returned non-JSON content")

        tokens_used = int(data.get("prompt_eval_count", 0)) + int(data.get("eval_count", 0))

        return {
            "summary": str(parsed.get("summary", "")).strip(),
            "key_points": [str(x) for x in (parsed.get("key_points") or [])],
            "tokens_used": tokens_used,
            "model": model,
        }

    # NDJSON con heartbeats (mismo patrón que /transcribe): Cloudflare corta la
    # conexión si el origen tarda ~100s en responder, así que mandamos la cabecera
    # y un latido cada 10s mientras Ollama trabaja; la última línea trae "result".
    task = asyncio.create_task(call_ollama())

    async def event_stream():
        try:
            while True:
                done, _ = await asyncio.wait({task}, timeout=10.0)
                if done:
                    break
                yield json.dumps({"status": "working"}) + "\n"

            try:
                result = task.result()
            except Exception as exc:
                yield json.dumps({"error": str(exc)}, ensure_ascii=False) + "\n"
                return

            yield json.dumps({"result": result}, ensure_ascii=False) + "\n"
        finally:
            if not task.done():
                task.cancel()

    return StreamingResponse(event_stream(), media_type="application/x-ndjson")


def _ollama_url(path: str) -> str:
    base = os.environ.get("OLLAMA_BASE_URL", "http://localhost:11434").rstrip("/")
    return base + path
