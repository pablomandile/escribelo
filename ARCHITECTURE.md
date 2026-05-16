# Architecture

Mapa técnico de Escribelo. Para setup y uso ver [README.md](README.md). Para reglas de negocio ver [BUSINESS_RULES.md](BUSINESS_RULES.md).

---

## 1. Vista general

```
┌───────────────────────────────────────────────────────────────┐
│  Browser (Vue 3 + Inertia, Ziggy, Tailwind, marked)           │
└─────────────────┬─────────────────────────────────────────────┘
                  │ Inertia (XHR + JSON props)
┌─────────────────▼─────────────────────────────────────────────┐
│  Laravel 13 (PHP 8.3+)                                        │
│  ├─ Routes / Controllers / Inertia::render                    │
│  ├─ Middleware: auth, verified, approved, admin               │
│  ├─ Eloquent models (MySQL)                                   │
│  ├─ Queue (database driver) — Jobs:                           │
│  │   • ProcessTranscriptionFile                               │
│  │   • SummarizeTranscription                                 │
│  └─ Services:                                                 │
│     • Transcriber (Local subprocess / Remote API)             │
│     • Summarizer (Ollama / Groq / Remote)                     │
│     • Worker (gestión de procesos: whisper, queue, tunnel)    │
└──┬────────────────┬─────────────────┬─────────────────────────┘
   │                │                 │
   │ subprocess     │ HTTP            │ HTTP
   ▼                ▼                 ▼
┌──────────────┐  ┌────────────┐  ┌────────────────────────┐
│ whisper_     │  │ Ollama     │  │ Remote worker          │
│  worker/     │  │ localhost  │  │ (FastAPI vía           │
│ (Python +    │  │ :11434     │  │  Cloudflare Tunnel)    │
│ faster-      │  │ gemma4:26b │  │ /transcribe /summarize │
│ whisper +    │  └────────────┘  └────────────────────────┘
│ ffmpeg arnndn)
└──────────────┘
        │
        ▼
   Groq (cloud)
```

Hay dos **modos de operación** controlados por `app_settings.mode`:

- **`local`** (default): Laravel spawnea `whisper_worker/transcribe.py` como subproceso por cada job, y habla con Ollama en `localhost:11434`. Todo el cómputo es local.
- **`host`**: Laravel hace HTTP a un worker FastAPI ([whisper_worker/api_server.py](whisper_worker/api_server.py)) expuesto típicamente via Cloudflare Tunnel. Útil cuando la GPU vive en otra máquina.

El selector de modo está en el admin panel y se persiste en la tabla `app_settings`. El helper [escribelo_mode()](app/helpers.php) lo lee con caché forever (invalidada al `set`).

---

## 2. Stack y dependencias críticas

### Backend (`composer.json`)

| Paquete | Para qué |
|---|---|
| `laravel/framework ^13.7` | Framework base |
| `inertiajs/inertia-laravel ^2.0` | Adapter Inertia para servir Vue desde PHP |
| `laravel/sanctum ^4.0` | Disponible (no usado activamente; sesiones cookie-based) |
| `laravel/socialite ^5.27` | Google OAuth |
| `laravel/breeze ^2.4` (dev) | Scaffold de auth (registración, password reset, email verify) |
| `tightenco/ziggy ^2.0` | `route()` helper compartido entre PHP y JS |
| `barryvdh/laravel-dompdf ^3.1` | Export a PDF |
| `james-heinrich/getid3 ^1.9` | Lectura de tags ID3 / metadata MP4 |

### Frontend (`package.json`)

| Paquete | Para qué |
|---|---|
| `@inertiajs/vue3 ^2.0` | Cliente Inertia para Vue 3 |
| `vue ^3.4` | Framework |
| `tailwindcss ^3.2` + `@tailwindcss/forms`, `@tailwindcss/vite` | Estilos |
| `vite ^8.0` + `laravel-vite-plugin ^3.1` | Build |
| `marked ^18` | Render markdown del resumen |

### Worker (`whisper_worker/requirements.txt`)

`faster-whisper`, `fastapi`, `uvicorn[standard]`, `python-multipart`, `httpx`. `openai-whisper` queda como fallback si `faster-whisper` no instala.

### Externos

- **MySQL** — esquema completo + sesiones + queue + cache.
- **FFmpeg** — denoise (arnndn) + conversión de formato del cleaned audio.
- **Ollama** — opcional, para resumen local.
- **Groq API** — opcional, para resumen en la nube.
- **Cloudflare Tunnel** (`cloudflared`) — opcional, para modo host.

---

## 3. Routing

Definido en [routes/web.php](routes/web.php). Hay tres clusters:

**Públicas / auth:** `/` (landing), grupo de Breeze en [routes/auth.php](routes/auth.php), y `/auth/google/redirect` + `/auth/google/callback` ([GoogleAuthController](app/Http/Controllers/Auth/GoogleAuthController.php)).

**Protegidas (`auth + approved`):**

| Recurso | Endpoints clave |
|---|---|
| Dashboard | `GET /escribelo` |
| Transcripciones | `POST /transcriptions`, `POST /transcriptions/from-paths`, `GET /transcriptions/{id}`, `PATCH /transcriptions/{id}/rename`, `PATCH /transcriptions/{id}/folder`, `PATCH /transcriptions/{id}/text`, `DELETE /transcriptions/{id}/text` (restaurar), `POST /transcriptions/{id}/summary`, `DELETE /transcriptions/{id}/summary` (cancelar), `DELETE /transcriptions/{id}` |
| Audio del archivo | `GET /transcriptions/{id}/audio`, `GET /transcriptions/{id}/audio/cleaned`, `GET /transcriptions/{id}/artwork`, `GET /transcriptions/{id}/download/{txt\|srt\|pdf}` |
| Cleaned audio | `POST /cleaned/replace`, `POST /cleaned/save-as-new`, `DELETE /cleaned` |
| Biblioteca | `GET /library/browse` (filesystem), `GET /folders`, `GET /folders/{id}`, `POST /folders`, `DELETE /folders/{id}` |
| Perfil | `GET /profile`, `PATCH /profile`, `PATCH /profile/settings`, `DELETE /profile` |

**Admin (`admin` middleware):**

| Recurso | Endpoints |
|---|---|
| Settings | `GET /admin/settings`, `PATCH /admin/settings/mode`, `PATCH /admin/settings/whisper-timeout`, `POST /admin/settings/refresh-gpu` |
| Users | `GET /admin/users`, `POST /admin/users/{id}/approve\|revoke`, `PATCH /admin/users/{id}/limit\|role`, `DELETE /admin/users/{id}` |
| Worker (Python) | `GET\|POST /admin/worker/status\|start\|stop\|restart` |
| Cloudflared | idem para `cloudflared` |
| Queue | idem para `php artisan queue:work` |

---

## 4. Capa de datos

### Entidades

```
User
 ├─ hasMany TranscriptionFile
 ├─ hasMany TranscriptionFolder
 └─ hasMany GroqUsage (1 por user por día)

TranscriptionFolder ──┐ (self-ref via parent_id, 2 niveles)
 ├─ belongsTo User    │
 ├─ belongsTo parent ─┘
 ├─ hasMany children
 └─ hasMany TranscriptionFile

TranscriptionFile
 ├─ belongsTo User
 ├─ belongsTo TranscriptionFolder (nullable)
 └─ hasOne Transcription

Transcription
 ├─ belongsTo TranscriptionFile
 └─ hasMany TranscriptionSegment

AppSetting  (k/v global, no FK)
```

### Tablas principales

- **`users`** — `name`, `email` (unique), `google_id` (unique, nullable), `password` (nullable cuando login es OAuth), `role` (`admin`/`user`), `approval_status` (`pending`/`approved`), `approved_at`, `audio_limit` (nullable = ilimitado), `settings` (JSON: `theme`, `summary_provider`, etc.), `email_verified_at`.
- **`transcription_folders`** — `user_id`, `parent_id` (nullable, FK self), `name`. Unique compuesto `(user_id, parent_id, name)`.
- **`transcription_files`** — `user_id`, `transcription_folder_id` (nullable), `original_name`, `stored_path` (absoluto si viene de la biblioteca; relativo bajo `audios/{user_id}/` si fue upload), `mime_type`, `size`, `duration_seconds`, `language`, `clean_audio` (bool), `cleaned_audio_path`, `model`, `status`, `progress` (0–100), `worker_pid`, `error_message`, `processed_at`. Indexado por `status` y `(user_id, created_at)`.
- **`transcriptions`** — `transcription_file_id` (unique), `text` (Whisper raw), `edited_text` (nullable, edición del usuario), `edited_at`, `effective_segments` (JSON de segmentos reconciliados), `metadata` (JSON), `summary`, `summary_metadata` (JSON: `key_points`, `model`, `tokens_used`, `provider`), `summary_status`, `summary_generated_at`.
- **`transcription_segments`** — `transcription_id`, `position`, `start_seconds`, `end_seconds`, `text`. Indexado por `(transcription_id, position)`.
- **`groq_usage`** — `user_id`, `date`, `requests_count`, `tokens_used`. Unique `(user_id, date)`.
- **`app_settings`** — `key` unique, `value` JSON. Singletones: `mode`, `whisper_timeout`.

Estándar Laravel: `password_reset_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs`.

---

## 5. Backend application layer

### Controllers ([app/Http/Controllers](app/Http/Controllers))

| Controller | Responsabilidad |
|---|---|
| [TranscriptionFileController](app/Http/Controllers/TranscriptionFileController.php) | Ciclo completo del archivo: upload, listar, mostrar, descargar, streamear audio, renombrar, mover carpeta, editar texto, restaurar, lanzar resumen, cancelar resumen, eliminar. Centro del sistema. |
| [TranscriptionFolderController](app/Http/Controllers/TranscriptionFolderController.php) | Crear / listar / borrar carpetas. Limita anidamiento a 2 niveles. |
| [AudioLibraryController](app/Http/Controllers/AudioLibraryController.php) | Browse del filesystem local. Devuelve subcarpetas + archivos con extensión de audio. |
| [ProfileController](app/Http/Controllers/ProfileController.php) | Editar perfil, settings (tema, provider de resumen), password, delete account. |
| [AccountController](app/Http/Controllers/AccountController.php) | Pantalla "pendiente de aprobación". |
| [Auth/GoogleAuthController](app/Http/Controllers/Auth/GoogleAuthController.php) | OAuth Socialite. Tres ramas: existing `google_id` → login, existing email → link, new → crear (admin si es el primero). |
| [Auth/*](app/Http/Controllers/Auth) | Scaffold de Breeze (login, register, password reset, email verify). |
| [Admin/UserController](app/Http/Controllers/Admin/UserController.php) | Aprobar/revocar usuarios, setear `audio_limit`, cambiar rol, eliminar. |
| [Admin/SettingsController](app/Http/Controllers/Admin/SettingsController.php) | Cambiar modo `local`/`host`, timeout de Whisper, detectar GPU. |
| [Admin/WorkerController](app/Http/Controllers/Admin/WorkerController.php) | Start/stop/restart del Python worker. |
| [Admin/CloudflareController](app/Http/Controllers/Admin/CloudflareController.php) | Idem para `cloudflared`. |
| [Admin/QueueController](app/Http/Controllers/Admin/QueueController.php) | Idem para `php artisan queue:work`. |

### Middleware ([bootstrap/app.php](bootstrap/app.php))

- `auth`, `verified` — estándar Laravel.
- `approved` → [EnsureUserApproved](app/Http/Middleware/EnsureUserApproved.php): redirige a `/account/pending` si `approval_status !== 'approved'`.
- `admin` → [EnsureUserIsAdmin](app/Http/Middleware/EnsureUserIsAdmin.php): `abort_unless($user?->isAdmin(), 403)`.
- `HandleInertiaRequests` — append al stack web; hidrata props compartidas (`auth.user`, `flash`, `errors`, etc.).

### Jobs (queue `database` driver)

- [ProcessTranscriptionFile](app/Jobs/ProcessTranscriptionFile.php) — transcribe via subprocess local o API remota. Timeout dinámico ([AppSetting::whisperTimeout()](app/Models/AppSetting.php)). `tries=1`. Si el worker remoto está offline, lanza `RemoteWorkerOfflineException` que dispara re-dispatch con delay de 60s.
- [SummarizeTranscription](app/Jobs/SummarizeTranscription.php) — resume via Ollama, Groq o RemoteSummarizer según provider. Timeout 900s. Soporta cancelación cooperativa: revisa `summary_status` antes y después del HTTP call.

### Services

```
app/Services/
├─ Transcriber/
│  ├─ TranscriberInterface.php
│  ├─ LocalProcessTranscriber.php     ── spawns python whisper_worker/transcribe.py
│  ├─ RemoteApiTranscriber.php        ── POST multipart al worker remoto (Guzzle)
│  └─ RemoteWorkerOfflineException.php
├─ Summarizer/
│  ├─ SummarizerInterface.php
│  ├─ OllamaSummarizer.php            ── HTTP a localhost:11434/api/chat, chunking >50k chars
│  ├─ GroqSummarizer.php              ── HTTP a Groq, chunking >8k chars, tracking en GroqUsage
│  ├─ RemoteSummarizer.php            ── HTTP al worker remoto /summarize
│  └─ SummarizerException.php
├─ Worker/
│  ├─ WorkerProcessManager.php        ── start/stop/status uvicorn (Python), PID file
│  ├─ CloudflareTunnelManager.php     ── start/stop/status cloudflared
│  └─ QueueWorkerManager.php          ── start/stop/status queue:work
├─ Audio/
│  └─ AudioMetadataReader.php         ── getID3, cache por (path + mtime) 1 día
└─ Transcription/
   └─ SegmentReconciler.php           ── LCS palabra-a-palabra entre texto original y editado
```

### Helpers

[app/helpers.php](app/helpers.php) define un único helper global:

- **`escribelo_mode(): string`** — lee `app_settings.mode` (default `'local'`). Centraliza el switch local/host.

---

## 6. Frontend application layer

### Bootstrap

[resources/js/app.js](resources/js/app.js) configura Inertia, Ziggy y el sync de tema (lee `auth.user.theme`, aplica clase `dark` al root, persiste en localStorage tras cada navegación). NProgress en color `#4B5563`.

### Pages

Mapeadas por glob `./Pages/**/*.vue`. Las más críticas:

- [Pages/Dashboard.vue](resources/js/Pages/Dashboard.vue) — listado, upload, drag-drop a carpetas, polling de estado de jobs, biblioteca filesystem, settings de modelo/lenguaje en localStorage, inline rename en cada fila.
- [Pages/Transcriptions/Show.vue](resources/js/Pages/Transcriptions/Show.vue) — vista completa de un archivo: reproducción con karaoke (highlight de segmento activo + click-to-seek con lead-in 0.25s), edición inline de nombre y texto, toggle audio original/cleaned, modal de artwork, resumen con render de markdown vía `marked`, polling de `summary_status` cada 2.5s.
- [Pages/Library/Index.vue](resources/js/Pages/Library/Index.vue) + [Show.vue](resources/js/Pages/Library/Show.vue) — UI de carpetas.
- [Pages/Profile/Edit.vue](resources/js/Pages/Profile/Edit.vue) + Partials — perfil, password, preferencias (tema, summary provider), delete account.
- [Pages/Admin/Users.vue](resources/js/Pages/Admin/Users.vue), [Settings.vue](resources/js/Pages/Admin/Settings.vue) — panel admin.
- [Pages/Account/Pending.vue](resources/js/Pages/Account/Pending.vue) — pantalla de espera.
- [Pages/Auth/*](resources/js/Pages/Auth) — login, register, password reset, email verify (Breeze).

### Layouts y componentes

- [Layouts/AuthenticatedLayout.vue](resources/js/Layouts/AuthenticatedLayout.vue), [GuestLayout.vue](resources/js/Layouts/GuestLayout.vue).
- Componentes compartidos en [Components/](resources/js/Components/): `Modal` (envoltura `<dialog>` con max-width variant), `ConfirmModal` (consumido por `useConfirm`), `ToastContainer` (consumido por `useToast` + flash de Inertia), `Dropdown`, `NavLink`, `ApplicationLogo`, botones, inputs, `DownloadFormatModal`.

### Composables

- [useToast](resources/js/composables/useToast.js) — `success/error/info`, integrado con flash de Inertia.
- [useConfirm](resources/js/composables/useConfirm.js) — devuelve `Promise<bool>`; expone `open({title, message, danger, …})`.

### Utilidades

- [utils/beep.js](resources/js/utils/beep.js) — WebAudio beep para notificar fin de transcripción. `primeBeepOnFirstGesture()` lo arma en el primer click/touch/keydown.

### Markdown del resumen

Render via `marked.parse()` + `v-html` en `Transcriptions/Show.vue`. Estilos scoped con `:deep()` para H2/H3/listas/`<hr>`/`<strong>` con soporte dark mode.

---

## 7. Flujo end-to-end de una transcripción

```
Browser                Laravel               Queue worker          whisper_worker (Python)
   │                     │                        │                         │
   │ POST /transcriptions│                        │                         │
   ├────────────────────►│                        │                         │
   │                     │ valida upload          │                         │
   │                     │ store en audios/uid/   │                         │
   │                     │ create row status=queued                         │
   │                     │ dispatch job ──────────►                         │
   │ 200 OK              │                        │                         │
   │◄────────────────────┤                        │                         │
   │                     │                        │ resolveTranscriber(mode)│
   │                     │                        │ status=processing       │
   │ poll /transcriptions/{id} (Inertia reload)   │                         │
   │ ↻ cada N segundos   │                        │ Symfony Process ─spawn─►│
   │                     │                        │                         │ loading_engine
   │                     │                        │  NDJSON stdout◄─────────│ loading_model
   │                     │                        │  progress callback      │ progress 0..100
   │                     │                        │  (update DB)            │ denoise_done
   │                     │                        │                         │ finalizing
   │                     │                        │ parse storage/          │
   │                     │                        │   transcripts/{id}.json │
   │                     │                        │ DB tx:                  │
   │                     │                        │  • Transcription        │
   │                     │                        │  • Segments[]           │
   │                     │                        │ status=completed        │
   │ render show page    │                        │                         │
   │◄────────────────────┤                        │                         │
```

**Status transitions (`transcription_files.status`):**

```
queued ──► enhancing (si clean_audio) ──► processing ──► completed
                                       └► failed
                                       └► waiting_for_worker (host mode, retry 60s)
```

**Edit → reconciliar segmentos:** cuando el usuario edita el texto en `Transcriptions/Show`, `PATCH /transcriptions/{id}/text` corre [SegmentReconciler](app/Services/Transcription/SegmentReconciler.php) que hace LCS palabra-a-palabra contra los segmentos originales. Palabras que sobreviven mantienen sus timestamps; palabras agregadas heredan el timestamp del vecino más cercano. Resultado se guarda en `transcriptions.effective_segments` (JSON) y se usa para el SRT y el karaoke.

**Resumen:** `POST /transcriptions/{id}/summary` setea `summary_status='queued'` y dispatcha `SummarizeTranscription`. El summarizer chunkea si el texto excede su `MAX_CHARS_PER_CHUNK` (50k para Ollama, 8k para Groq), hace map-reduce de los parciales, y guarda `summary` (markdown completo), `summary_metadata.key_points`, `summary_metadata.tokens_used`, `summary_metadata.model`. El frontend polea cada 2.5s mientras esté en `queued|processing`.

**Cancelación de resumen:** `DELETE /transcriptions/{id}/summary` marca `summary_status='failed'`; el job revisa el flag antes y después del HTTP y descarta el resultado si fue cancelado.

---

## 8. Integraciones externas

| Servicio | Cómo se invoca | Auth | Cuándo |
|---|---|---|---|
| Whisper local | subprocess de `python whisper_worker/transcribe.py` con `--file --output --model --language --clean-audio --cleaned-output` | n/a | modo `local` |
| Whisper remoto | POST `{REMOTE_WORKER_URL}/transcribe` multipart | Bearer `REMOTE_WORKER_TOKEN` | modo `host` |
| Ollama | POST `{OLLAMA_BASE_URL}/api/chat` con `format: json` | n/a | resumen, modo `local` |
| Groq | POST `https://api.groq.com/openai/v1/chat/completions` | Bearer `GROQ_APIKEY` | resumen, opt-in por usuario |
| Google OAuth | Socialite → redirect a Google → callback | OAuth 2.0 | login |
| Cloudflare Tunnel | binario `cloudflared` administrado vía `CloudflareTunnelManager` | tokens en `~/.cloudflared/config.yml` | modo `host` |

---

## 9. Worker de Whisper (Python)

[whisper_worker/](whisper_worker/) tiene dos entry points:

- **[transcribe.py](whisper_worker/transcribe.py)** — script CLI que recibe `--file --output --model --language --clean-audio --cleaned-output`. Emite NDJSON al stdout con `{phase, …}` y `{progress: N}`. Detecta CUDA via `ctranslate2.get_cuda_device_count()`. Si hay GPU Ampere+, usa `float16`; si no, fallback a CPU con `int8`/`float32`. Para denoise usa FFmpeg con el filtro `arnndn` y el modelo [models/cb.rnnn](whisper_worker/models/cb.rnnn). Conversión final del cleaned audio a MP3 192 kbps.
- **[api_server.py](whisper_worker/api_server.py)** — FastAPI con 3 endpoints (`/health`, `/transcribe`, `/summarize`). Streamea el mismo NDJSON via SSE/streaming response.

[WorkerProcessManager](app/Services/Worker/WorkerProcessManager.php) gestiona el ciclo de vida del FastAPI:

- En Windows: genera `worker/launch_worker.bat` y lanza con `start /B`.
- En Unix: `nohup … &` envuelto en `proc_open()`.
- PID se escribe a `storage/worker/worker.pid` desde el propio FastAPI (vía env `ESCRIBELO_PID_FILE`).
- Logs van a `storage/worker/worker.log` (truncado en cada start).
- Env passing: `OLLAMA_BASE_URL`, `OLLAMA_SUMMARY_MODEL`, `PYTHONIOENCODING=utf-8`, `PYTHONUSERBASE`, `CUDA_PATH`, etc.

---

## 10. Storage layout

```
storage/
├─ app/
│  ├─ audios/{user_id}/{uuid}.{ext}       # uploads del usuario
│  └─ cleaned/{transcription_file_id}.mp3 # output del denoise
├─ transcripts/
│  └─ {transcription_file_id}.json        # output completo de Whisper
└─ worker/
   ├─ worker.pid
   └─ worker.log
```

`transcription_files.stored_path` puede ser:

- Relativo (`audios/{user_id}/abc.mp3`) si el archivo se subió via upload.
- Absoluto (`D:\Music\foo.mp3`) si se referencia desde la biblioteca local (`storeFromPaths`).

[TranscriptionFile::absolutePath()](app/Models/TranscriptionFile.php) maneja los dos casos.

---

## 11. Seguridad — postura actual y riesgos

### Lo que está bien

- **Autorización por dueño consistente** en `TranscriptionFileController` y `TranscriptionFolderController` (`abort_unless($file->user_id === $request->user()->id, 403)` y/o `whereBelongsTo($user)`).
- **CSRF** en toda la sesión web (stack default de Laravel).
- **Eloquent parametrizado** en todas las queries; los pocos `orderByRaw` / `whereRaw` no interpolan input del usuario.
- **Aprobación gate** middleware-enforced; primer usuario auto-admin.
- **Roles** simples (admin/user) verificados en middleware `admin`.

### Riesgos identificados

1. **Bypass de `audio_limit` en `POST /transcriptions`.** `User::canUploadMore()` existe y se chequea en `storeFromPaths()`, pero `store()` ([TranscriptionFileController:99-139](app/Http/Controllers/TranscriptionFileController.php)) no lo invoca. Un usuario con límite puede subir ilimitadamente por upload directo.

2. **Path traversal / disclosure de filesystem en `AudioLibraryController::browse`.** Acepta `path` arbitrario y hace `scandir()` sin whitelist. El filtro por extensión esconde no-audio pero igualmente revela la estructura completa del filesystem del server.

3. **XSS potencial en el resumen** (`Transcriptions/Show.vue` → `v-html="summaryHtml"` con `marked.parse()`). Hoy el contenido viene del LLM, no del usuario directamente — pero la transcripción la dicta el audio, y el LLM puede emitir tags HTML. Es self-XSS porque cada usuario solo ve sus propios resúmenes; riesgo bajo pero documentado. Mitigación recomendada: agregar `DOMPurify`.

4. **Groq rate limit no se enforza.** Se trackea el uso en `groq_usage` y se muestra en la UI, pero no hay corte server-side al pasarse del cuota diaria. El bloqueo lo hace Groq con 429.

5. **Linking de Google OAuth a cuenta existente por email sin re-verificar.** Si el atacante registra `foo@bar.com` y luego loguea con Google con ese email (pero el email del registro no estaba verificado), el `email_verified_at` se marca `now()` al linkear. Mitigación: confirmar ownership del email antes de linkear.

6. **Worker PID kill confiando en el número.** Si el PID se reusa por otro proceso entre lecturas, podría matarse algo no relacionado. Mitigación: comparar también `start_time`/cmdline.

7. **Sin retention / quota de disco.** Audios originales, cleaned audios, JSON de transcripts, exports — todo crece sin limpieza. Riesgo operativo, no de seguridad.

8. **`GROQ_APIKEY` y URL no se loguean explícitamente**, pero hay `Log::info('Groq request', […])`; revisar que no se filtre el header `Authorization` accidentalmente al loguear excepciones.

9. **`queue:work` corre `--tries=1`** y no se relanza automáticamente al `queue:restart`. Si el worker muere, hay que relanzarlo manualmente.

10. **Cleanup de la cola de resumen via `payload LIKE` substring match** (búsqueda fragile, sensible a cambios de serialización de Laravel).

---

## 12. Performance notes

- **Whisper en GPU vs CPU**: en Ampere+ con `float16`, `medium` corre ~5–10x real-time; en CPU `int8` cae a sub-real-time. La elección del modelo es la palanca más grande.
- **Polling de Inertia** en Show.vue es `reload({ only: ['file', 'groqUsage'] })` cada 2.5s; eficiente porque Inertia trae solo esas props.
- **`AudioMetadataReader`** cachea getID3 por `(path, mtime)` durante 24h — clave porque getID3 abre el archivo entero.
- **`AppSetting::get()`** usa `Cache::rememberForever`, invalidado en `set()`. Cada lectura del modo es O(1) en memoria.
- **Chunking de resumen**: Ollama acepta chunks de 50k chars (~12k tokens) gracias a `num_ctx=32768`; Groq se limita a 8k para no quemar tier gratuito.

---

## 13. Deuda técnica priorizada

| # | Item | Severidad | Costo aprox. |
|---|---|---|---|
| 1 | Chequear `canUploadMore()` también en `store()` | Alta | 5 min |
| 2 | Whitelist de paths en `AudioLibraryController` | Alta | 1 h |
| 3 | Sanitizar HTML del resumen con DOMPurify | Media | 30 min |
| 4 | Hard-block Groq cuando se excede `free_tier` | Media | 1 h |
| 5 | Re-verificar email antes de link con Google | Media | 1 h |
| 6 | Retention/cleanup de uploads y cleaned audios | Media | 4 h |
| 7 | Comando `php artisan` para limpiar PIDs huérfanos y jobs zombies | Media | 2 h |
| 8 | Test suite (hoy `tests/` es el scaffold de Breeze, sin specs propios) | Media | grande |
| 9 | Reemplazar `payload LIKE` por purge nativo de Laravel | Baja | 1 h |
| 10 | Supervisor (systemd / nssm) para el queue worker, en vez de cmd window | Baja | 1 h |
| 11 | Hint de tipo más estricto en `effective_segments` (DTO en vez de JSON suelto) | Baja | 4 h |
| 12 | Logs estructurados con contexto consistente (user_id en todos los `Log::*`) | Baja | 2 h |

---

## 14. Sugerencias de mejora (sin orden)

- **Storage en S3 / R2** opcional via `FILESYSTEM_DISK`. La app ya usa el Storage facade indirectamente; quedaría aislar accesos al filesystem absoluto.
- **Job per-segment para Whisper** — partir audios largos y procesar en paralelo (varios GPUs / workers). Aumenta complejidad pero baja latencia.
- **Streaming de progreso real al frontend con WebSockets** (Laravel Reverb) en vez de polling de 2.5s.
- **Cache de transcripciones por hash de audio** — si el mismo archivo se sube dos veces, reusar.
- **Tagging de transcripciones** además de carpetas (más flexible que jerarquía estricta).
- **Búsqueda full-text** en transcripciones (MySQL FULLTEXT o Meilisearch).
- **Multi-tenant** — hoy hay un único pool de usuarios. Para SaaS se necesita `tenant_id` o DBs por tenant.
- **Audit log** de acciones admin (aprobaciones, cambios de role, eliminaciones).
- **Tests E2E** con Dusk para los flujos de upload → transcribe → summarize.
