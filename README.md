# Escribelo

Plataforma self-hosted de transcripción y resumen de audio. Subís un archivo (o lo referenciás desde una carpeta local), Whisper lo transcribe con timestamps a nivel segmento, y opcionalmente un LLM (Ollama local o Groq) genera un resumen estructurado en markdown. Cada usuario tiene su biblioteca con carpetas anidables, edición de texto con realineado automático de timestamps, y export a TXT / SRT / PDF.

---

## Stack

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3+, Laravel 13, Inertia.js (server adapter) |
| Frontend | Vue 3 (Composition API + `<script setup>`), Inertia v2, Tailwind 3, Ziggy |
| Build | Vite 8, `laravel-vite-plugin`, `@tailwindcss/vite` |
| DB | MySQL (driver `mysql`), sesiones y queue en database driver |
| Auth | Breeze (email + password) + Socialite (Google OAuth) |
| Transcripción | `whisper_worker/` (Python + FastAPI + faster-whisper / openai-whisper) |
| Denoise | FFmpeg con filtro `arnndn` + modelo RNNoise `cb.rnnn` |
| Resumen | Ollama local (default `gemma4:26b`) o Groq (`llama-3.1-8b-instant`) |
| PDF | `barryvdh/laravel-dompdf` |
| Metadata audio | `james-heinrich/getid3` |
| Markdown | `marked` (frontend, render del resumen) |

Hay un modo "host" alternativo en el que la transcripción/resumen viven en un worker remoto expuesto por Cloudflare Tunnel — la app PHP consume su API en vez de levantar el subproceso local. El switch local↔host se hace desde el admin panel y persiste en `app_settings`. Es el modo con el que la app corre en un hosting compartido (sin GPU) delegando el cómputo a una PC con GPU: ver [Modo host](#modo-host-worker-remoto-vía-cloudflare-tunnel).

---

## Requisitos

- PHP 8.3+ (testeado con `php-8.4.21`; `composer.json` declara `^8.3`)
- Composer 2.x
- Node 20+ y npm
- MySQL 8.x (o MariaDB compatible)
- Python 3.10+ con `pip` (para el worker de Whisper)
- FFmpeg en `PATH` (denoise, metadata, conversión de formatos)
- Opcional: GPU NVIDIA con CUDA (Ampere+ para float16; fallback a CPU automático)
- Opcional: [Ollama](https://ollama.com) en `localhost:11434` con un modelo de resumen instalado
- Opcional: API key de [Groq](https://groq.com) si querés resumir en la nube

---

## Setup

### 1. Clonar y dependencias

```bash
git clone <repo> escribelo
cd escribelo
composer install
npm install
```

### 2. Variables de entorno

```bash
cp .env.example .env
php artisan key:generate
```

Editar `.env`:

| Variable | Para qué |
|---|---|
| `APP_URL` | URL base (afecta al redirect de Google OAuth) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión MySQL |
| `WHISPER_PYTHON` | Ejecutable de Python (default `python`) |
| `WHISPER_MODEL` | Modelo Whisper default por usuario (default `small`) |
| `WHISPER_TIMEOUT` | Timeout en segundos del subproceso (default `1800`) |
| `OLLAMA_BASE_URL` | URL de Ollama (default `http://localhost:11434`) |
| `OLLAMA_SUMMARY_MODEL` | Modelo de resumen (default `gemma4:26b`) |
| `GROQ_APIKEY` | API key (opcional) si vas a usar Groq |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` | OAuth con Google |
| `REMOTE_WORKER_URL`, `REMOTE_WORKER_TOKEN` | Solo si vas a usar modo "host". La URL puede pisarse después desde `/admin/settings` sin tocar el `.env` |
| `REMOTE_WORKER_MANAGE_LOCALLY`, `CLOUDFLARED_MANAGE_LOCALLY` | `false` en hosting (oculta los paneles de start/stop del worker y cloudflared, que solo tienen sentido en la máquina que los corre) |

### 3. Base de datos

```bash
php artisan migrate --seed
```

El primer usuario que se registra (email o Google) queda automáticamente como **admin** y aprobado. Los siguientes quedan en `approval_status = pending` hasta que un admin los apruebe desde `/admin/users`.

### 4. Worker de Whisper (Python)

```bash
cd whisper_worker
pip install -r requirements.txt
cd ..
```

`faster-whisper` es la opción preferida (más rápida, soporta CUDA via CTranslate2). Si no se puede instalar, el worker hace fallback a `openai-whisper`.

En modo "local" (default) Laravel spawnea `whisper_worker/transcribe.py` como subproceso por cada job. No hace falta arrancar nada manualmente.

En modo "host" hay que levantar el server FastAPI — desde el admin panel (`/admin/worker/start`) o manualmente:

```bash
python -m uvicorn whisper_worker.api_server:app --host 127.0.0.1 --port 8765
```

### 5. Frontend

Build de producción:

```bash
npm run build
```

Dev con HMR:

```bash
npm run dev
```

### 6. Levantar la app

**Opción A — `start-dev.bat` (Windows / Laragon):** doble click. Abre tres ventanas: `php artisan serve`, `npm run dev`, `php artisan queue:work`.

**Opción B — `composer dev`:** corre `serve`, `queue:listen`, `pail` (logs) y `vite` en un único proceso vía `concurrently`.

**Opción C — manual:**

```bash
php artisan serve --port=8001
php artisan queue:work --tries=1 --timeout=1800   # en otra terminal
npm run dev                                       # en otra terminal
```

---

## Cómo usar

1. Registrate (email + password o Google).
2. Si no sos el primer usuario, esperá aprobación en `/account/pending`.
3. Desde el dashboard cargás un audio de dos maneras: **Subir archivo** (upload HTTP — la única que funciona cuando la app corre en un server remoto) o **Elegir de la biblioteca** (referencia un archivo ya presente en el disco de la máquina que corre la app, sin duplicarlo).
4. Elegís modelo Whisper (`tiny`/`base`/`small`/`medium`/`large`/`large-v3`/`turbo`) e idioma. Un badge en el dashboard indica dónde se procesa (en esta máquina o en el worker remoto vía túnel, según el modo).
5. Opcional: marcar "reducir ruido" para que el worker genere una copia denoised que podés escuchar, comparar y elegir si reemplaza al original.
6. Cuando el estado pasa a `completed`, abrís la transcripción y podés:
   - Reproducir el audio con karaoke (click en cualquier palabra salta a su timestamp).
   - Editar el texto — los segmentos se realinean por LCS palabra-a-palabra para que el SRT siga sincronizado.
   - **Re-transcribir** con otro modelo (p.ej. si `small` no alcanzó, reintentar con `medium`) — pisa la transcripción anterior.
   - Renombrar el archivo inline.
   - Generar un resumen estructurado en markdown (Ollama o Groq según preferencia; en modo host lo hace el worker remoto).
   - Descargar TXT, SRT o PDF.
   - Mover a una carpeta.
   - **Borrar el audio** del server para liberar espacio — la transcripción y el resumen se conservan, y podés **resubir el archivo** más adelante para volver a reproducirlo o re-transcribir.

---

## Comandos útiles

```bash
# Limpiar caches después de tocar config/ o .env
php artisan config:clear
php artisan optimize:clear

# Reiniciar workers de cola (NECESARIO tras cambios en config/ porque
# el worker es un proceso largo con la config booteada en memoria)
php artisan queue:restart

# Tests
composer test

# Linter PHP
./vendor/bin/pint

# Logs en vivo
php artisan pail
```

---

## Estructura de directorios (alto nivel)

```
escribelo/
├── app/
│   ├── Http/Controllers/        # endpoints
│   ├── Jobs/                    # ProcessTranscriptionFile, SummarizeTranscription
│   ├── Models/                  # User, TranscriptionFile, Transcription, …
│   ├── Services/
│   │   ├── Transcriber/         # Local / Remote
│   │   ├── Summarizer/          # Ollama / Groq / Remote
│   │   ├── Worker/              # gestión de procesos Python, queue, cloudflared
│   │   ├── Audio/               # metadata ID3
│   │   └── Transcription/       # SegmentReconciler (LCS)
│   └── helpers.php              # escribelo_mode()
├── resources/js/
│   ├── Pages/                   # Inertia pages (Dashboard, Transcriptions/Show, Admin/…, Auth/…)
│   ├── Layouts/                 # AuthenticatedLayout, GuestLayout
│   ├── Components/              # Modal, Dropdown, ConfirmModal, ToastContainer, …
│   ├── composables/             # useToast, useConfirm
│   └── utils/                   # beep.js
├── whisper_worker/              # Python: transcribe.py, api_server.py, models/cb.rnnn
├── routes/web.php               # rutas web (auth, transcriptions, folders, admin)
├── routes/auth.php              # rutas de Breeze
└── storage/
    ├── app/audios/{user_id}/    # uploads
    ├── cleaned/                 # audio denoised
    ├── transcripts/             # JSON del worker
    └── worker/                  # PID + log de uvicorn
```

Para detalles técnicos ver [ARCHITECTURE.md](ARCHITECTURE.md). Para reglas de negocio y workflows ver [BUSINESS_RULES.md](BUSINESS_RULES.md).

---

## Modo host (worker remoto vía Cloudflare Tunnel)

Escenario: la app corre en un hosting (sin GPU) y el cómputo pesado corre en una PC con GPU. El server le habla al worker por HTTPS a través de un túnel de Cloudflare.

```
Hosting (Laravel + MySQL + cron de cola)
   │  HTTPS  (Bearer REMOTE_WORKER_TOKEN)
   ▼
https://xxxx.trycloudflare.com   ← cloudflared (quick tunnel) en la PC
   ▼
FastAPI worker 127.0.0.1:8765    ← faster-whisper (GPU) + proxy a Ollama
```

**En la PC (Windows):** correr dos procesos — el worker (`python -m uvicorn whisper_worker.api_server:app --host 127.0.0.1 --port 8765 --timeout-keep-alive 120`, con env `ESCRIBELO_REMOTE_TOKEN`, `OLLAMA_BASE_URL`, `OLLAMA_SUMMARY_MODEL`) y el túnel (`cloudflared tunnel --url http://localhost:8765`). El flag `--timeout-keep-alive 120` importa: debe superar los ~90s del pool de conexiones de cloudflared o aparecen 502 intermitentes.

**En el server:** `.env` con `REMOTE_WORKER_TOKEN` (idéntico al del worker), `REMOTE_WORKER_MANAGE_LOCALLY=false`, `CLOUDFLARED_MANAGE_LOCALLY=false`; modo `host` en `/admin/settings`; y algo que procese la cola (en hosting compartido: cron cada minuto con `queue:work --stop-when-empty`).

**La URL del túnel** (quick tunnel) cambia en cada reinicio de cloudflared. Por eso es editable en caliente desde `/admin/settings` → campo "URL del worker" (AppSetting `remote_worker_url`, pisa al `.env` sin recachear config ni SSH).

**Por qué streaming:** Cloudflare corta conexiones cuyo origen tarda ~100s en responder. `/transcribe` y `/summarize` responden NDJSON en streaming (heartbeats mientras el trabajo avanza, resultado en la última línea), así una transcripción de una hora o un resumen con el modelo frío no chocan contra ese límite. El server además reintenta automáticamente los 502/503/504 transitorios del túnel.

---

## Troubleshooting

**`Ollama devolvió un error (404)` al resumir.** El queue worker tiene la config vieja en memoria. `php artisan queue:restart` y volver a lanzarlo (no se relanza solo con `--tries=1` sin supervisor).

**502 `origin_bad_gateway` de Cloudflare al usar el túnel.** Suele ser transitorio (típico justo después de reiniciar el worker: cloudflared reusa conexiones keep-alive muertas). El server ya reintenta solo; si persiste, verificar que el worker corra con `--timeout-keep-alive 120` y que la URL del túnel en `/admin/settings` sea la vigente (cambia en cada reinicio de cloudflared).

**Whisper falla con `CUDA out of memory`.** Bajá a un modelo más chico (`small` o `medium`) o forzá CPU.

**`gemma4:26b not found` después de borrar gemma3.** `ollama pull gemma4:26b` (~17 GB).

**Las migraciones piden PHP 8.4 pero el CLI tiene 8.1.** Ajustá `PATH` para apuntar a tu PHP 8.3+ (en Laragon: `set PATH=C:\laragon\bin\php\php-8.4.21-Win32-vs17-x64;%PATH%`).

**El primer usuario quedó como `pending`.** Promovelo a mano:
```php
php artisan tinker
$u = \App\Models\User::first();
$u->update(['role' => 'admin', 'approval_status' => 'approved', 'approved_at' => now()]);
```

---

## Licencia

Proyecto privado.
