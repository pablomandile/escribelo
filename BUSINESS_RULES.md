# Business Rules

Reglas de negocio, workflows y restricciones de Escribelo. Para el mapa técnico ver [ARCHITECTURE.md](ARCHITECTURE.md). Para setup ver [README.md](README.md).

---

## 1. Cuentas y aprobación

### Roles

- **`admin`**: acceso al panel `/admin/*` (aprobar usuarios, cambiar modo, gestionar worker/queue/cloudflared, ajustar timeouts, ver/borrar usuarios).
- **`user`**: usuario regular.

### Estados de aprobación

- **`pending`**: cuenta creada, no puede acceder al dashboard ni a las rutas protegidas. Solo ve `/account/pending` y puede hacer logout.
- **`approved`**: cuenta activa. Se setea `approved_at = now()`.

### Reglas de creación

1. **El primer usuario** que se registra (sea por email o Google) queda automáticamente como `role = admin` y `approval_status = approved`. Es la forma de bootstrap del sistema.
2. **Los demás** usuarios quedan como `role = user`, `approval_status = pending`, hasta que un admin los apruebe desde `/admin/users`.
3. Aplica tanto a registro por email/password como a primer login via Google OAuth.

### Reglas de Google OAuth

Tres ramas en [GoogleAuthController](app/Http/Controllers/Auth/GoogleAuthController.php):

1. **Existe un usuario con ese `google_id`** → login directo.
2. **Existe un usuario con ese email pero sin `google_id`** → se linkea: se guarda `google_id`, se setea `email_verified_at = now()`, login.
3. **No existe usuario** → crear con la regla de "primer usuario admin" descripta arriba.

### Eliminación de cuenta

- El usuario puede borrar su cuenta desde `/profile` (acción destructiva con confirmación).
- Cascada: borra sus `transcription_files` (y por cascada `transcriptions` + `transcription_segments`), `transcription_folders`, `groq_usage`.
- Los archivos físicos (audios, cleaned, JSON, exports) **no se limpian automáticamente** — deuda técnica conocida.

---

## 2. Cuotas y límites

### Por usuario

- **`users.audio_limit`** — número máximo de archivos que el usuario puede tener cargados.
  - `null` = ilimitado.
  - Un valor entero N = el usuario no puede tener más de N archivos.
  - Setear desde `/admin/users` (`PATCH /admin/users/{id}/limit`).
- **Chequeo del límite**: [User::canUploadMore()](app/Models/User.php) compara `audioUsage()` (count de `transcription_files`) contra `audio_limit`.
- ⚠️ **Bug conocido**: el chequeo se aplica en `POST /transcriptions/from-paths` pero **no** en `POST /transcriptions` (upload directo). Listado en deuda técnica como prioridad alta.

### Por archivo

- **Tamaño máximo upload**: 512 MB por archivo, hasta 10 archivos por request ([TranscriptionFileController::store](app/Http/Controllers/TranscriptionFileController.php)).
- **Formatos aceptados**: `mp3`, `wav`, `m4a`, `mp4`, `webm`, `ogg`, `oga`, `flac`, `aac`. Validación por `mimes:` y por extensión (en `storeFromPaths`).

### Por API externa

- **Groq free tier** ([config/services.php](config/services.php)):
  - 14.400 requests / día (`GROQ_FREE_RPD`).
  - 500.000 tokens / día (`GROQ_FREE_TPD`).
  - Tracking en `groq_usage` (una fila por `(user_id, date)`).
  - La UI muestra el uso acumulado y un warning cuando se acerca al límite, pero **no hay corte server-side**: el bloque lo hace Groq devolviendo 429.

### Texto editado

- **`transcription.edited_text`** acepta hasta 1.000.000 caracteres (~1 MB).

### Nombres

- **`transcription_files.original_name`**: máx 255 chars. Trim automático al renombrar. No puede quedar vacío.
- **`transcription_folders.name`**: 255 chars. Único por `(user_id, parent_id, name)` — no puede haber dos carpetas hermanas con el mismo nombre.

---

## 3. Modelos de Whisper permitidos

Validados en el `store()` ([TranscriptionFileController:105](app/Http/Controllers/TranscriptionFileController.php#L105)):

```
tiny, base, small, medium, large, large-v2, large-v3, turbo
```

Default del sistema: `WHISPER_MODEL` env (default `small`). El usuario elige modelo por upload; el último elegido se persiste en `localStorage` del navegador como `escribelo_last_model` para conveniencia.

---

## 4. Carpetas

- **Nesting máximo: 2 niveles.** Una carpeta raíz puede tener hijas, pero las hijas no pueden tener nietas. El chequeo está en [TranscriptionFolderController](app/Http/Controllers/TranscriptionFolderController.php) al crear: si `parent_id` apunta a una carpeta que ya tiene `parent_id`, se rechaza.
- **Ownership**: una carpeta pertenece a un único `user_id`. No se comparten entre usuarios.
- **Nombre único** por `(user_id, parent_id, name)` (constraint de DB).
- **Borrado en cascada**: si borrás una carpeta, sus hijas se borran y los `transcription_files` pasan a `transcription_folder_id = null` (gracias al `null_on_delete` del FK; las hijas sí se cascadean). En el modal de confirmación se le avisa al usuario.
- **Drag-and-drop**: en el dashboard se puede arrastrar un archivo a una carpeta. La acción dispara `PATCH /transcriptions/{id}/folder`.
- **Mover en bulk**: `POST /transcriptions/move-bulk` mueve varios archivos a la vez, validando que la carpeta destino pertenezca al usuario.
- **Listado dentro de una carpeta**: orden ascendente por `LOWER(original_name)` ([TranscriptionFileController:51](app/Http/Controllers/TranscriptionFileController.php#L51)). El feed "recientes" y "sin ordenar" en el dashboard usan `latest()` por `created_at`.

---

## 5. State machines

### `transcription_files.status`

```
queued
  │
  ├── enhancing       (si clean_audio=true; durante el denoise)
  │      │
  │      ▼
  └──► processing     (Whisper corriendo)
              │
              ├──► completed       (segments y text guardados)
              ├──► failed          (error_message poblado)
              └──► waiting_for_worker (modo host, worker remoto offline → re-dispatch en 60s)
```

- **`queued`** se setea en `TranscriptionFileController::store()` al crear el archivo.
- **`enhancing`** / **`processing`** se setea en `ProcessTranscriptionFile::handle()` al empezar el job. `progress` (0–100) se actualiza desde el callback que recibe los eventos del worker.
- **`completed`** se setea cuando se parseó el JSON y se persistieron `Transcription` + segments. `processed_at = now()`.
- **`failed`** queda con `error_message` legible.
- **`waiting_for_worker`** es transitorio: cuando `RemoteApiTranscriber` no puede contactar al worker, lanza `RemoteWorkerOfflineException` y el job se re-dispatcha con delay de 60s.

### `transcriptions.summary_status`

```
idle (default)
  │
  ▼ POST /transcriptions/{id}/summary
queued
  │
  ▼ SummarizeTranscription::handle() arranca
processing
  │
  ├──► completed   (summary + key_points guardados)
  └──► failed      (error en summary_metadata, o cancelación cooperativa)
```

- **Cancelación cooperativa**: `DELETE /transcriptions/{id}/summary` marca `summary_status = failed` aunque el job esté en `processing`. El job revisa el flag antes y después del HTTP al LLM; si lo encuentra en `failed`, descarta el resultado.

---

## 6. Provider de resumen

- Cada usuario tiene un setting `summary_provider` en `users.settings` JSON (default `'groq'`).
- Valores: `'groq'` o `'ollama'`. La elección se hace en `/profile`.
- En modo `host`, se ignora la preferencia del usuario y se usa `RemoteSummarizer` (el worker remoto resume internamente).
- **Fallback**: si elige `groq` pero `GROQ_APIKEY` no está configurada, se muestra un banner pidiéndole que cambie a Ollama desde `/profile`.

### Estructura del resumen

El prompt ([app/Services/Summarizer/OllamaSummarizer.php](app/Services/Summarizer/OllamaSummarizer.php)) instruye al LLM a devolver `summary` como markdown estructurado con:

1. Párrafo introductorio (2–4 oraciones, sin heading).
2. `## Resumen general` — 2–4 párrafos de contexto.
3. `## Principales puntos` con subsecciones `### 1. Título`, `### 2. Título`, etc. (6–15 según densidad del audio).
4. `## Breve resumen final` — 1–2 párrafos.
5. `## Temas principales` — lista corta (5–12 bullets) de ejes/temas.
6. `## Tecnologías y herramientas mencionadas` — **condicional**: solo si el audio es técnico y se mencionan herramientas concretas; sino se omite.

Reglas estrictas del prompt:

- Tercera persona, tono neutral.
- Sin saludos tipo "Claro, X".
- Sin adjetivos opinativos ("hermosa", "conmovedora", etc.).
- Idioma respetando el del audio.
- `key_points` se devuelve como array vacío `[]` (los puntos ya están dentro del markdown).
- Para textos largos: chunking con map-reduce. Cada chunk produce notas; un paso final consolida en la estructura completa.

---

## 7. Edición de transcripción

- Se accede al modo edición desde el botón "✎ Editar" en `Transcriptions/Show`.
- El texto se guarda en `transcriptions.edited_text` (no se pierde el `text` original de Whisper).
- Al guardar se ejecuta [SegmentReconciler](app/Services/Transcription/SegmentReconciler.php):
  - Hace LCS (longest common subsequence) palabra-a-palabra entre el texto editado y el texto original.
  - Palabras que sobreviven mantienen su `start_seconds` / `end_seconds` original.
  - Palabras agregadas heredan el timestamp del vecino más cercano.
  - Palabras borradas desaparecen del SRT.
- El resultado se guarda en `transcriptions.effective_segments` (JSON).
- **El SRT y el karaoke usan `effective_segments` si existe**, sino los segments originales.
- **Restaurar al original** (`DELETE /transcriptions/{id}/text`) limpia `edited_text`, `edited_at`, `effective_segments`. Whisper vuelve a ser la fuente de verdad.

---

## 8. Audio limpio (denoise)

- El usuario marca el checkbox "reducir ruido" al subir.
- Durante `enhancing`, FFmpeg corre el filtro `arnndn` con [models/cb.rnnn](whisper_worker/models/cb.rnnn) y produce un MP3 192 kbps.
- Path resultante: `storage/app/cleaned/{transcription_file_id}.mp3` → `transcription_files.cleaned_audio_path`.
- Después de `completed`, el usuario tiene tres acciones disponibles:
  1. **Reemplazar el original** (`POST /cleaned/replace`): sobrescribe el archivo original con la versión limpia (re-encodea al formato original).
  2. **Guardar como copia "_NR"** (`POST /cleaned/save-as-new`): crea un nuevo `TranscriptionFile` con sufijo `_NR` en el `original_name`, copiando la cleaned a `audios/{user_id}/`. El original queda intacto.
  3. **Descartar** (`DELETE /cleaned`): borra el archivo cleaned, deja `cleaned_audio_path = null`. Si después querés volver a generar, hay que re-transcribir el original con el checkbox marcado.

---

## 9. Export

`GET /transcriptions/{id}/download/{format}` con `format ∈ {txt, srt, pdf}`.

- **TXT**: texto plano (usa `edited_text` si existe, sino `text`).
- **SRT**: subtítulos sincronizados, usando `effective_segments` si existe.
- **PDF**: render via `barryvdh/laravel-dompdf`, vista en `resources/views/exports/`.
- El nombre del archivo descargado deriva del `original_name` (con `pathinfo(..., PATHINFO_FILENAME)`).

---

## 10. Audio library (filesystem browser)

- Endpoint: `GET /library/browse?path=...` ([AudioLibraryController](app/Http/Controllers/AudioLibraryController.php)).
- Sirve para usuarios que tienen una colección de audios ya en disco y no quieren duplicarlos al storage de la app.
- Importación: `POST /transcriptions/from-paths` con una lista de paths absolutos. La app guarda esos paths como `stored_path` absoluto (no copia el archivo).
- ⚠️ **Riesgo**: no hay whitelist de paths permitidos. Cualquier usuario aprobado puede listar cualquier directorio que el server pueda leer. Listado en deuda técnica como prioridad alta.

---

## 11. Permisos y ownership

- Todas las rutas de recurso verifican que `recurso.user_id === auth()->id()` antes de cualquier operación (excepto admin endpoints).
- Las queries usan `whereBelongsTo($user)` consistentemente.
- Movimiento de archivos entre carpetas: validado en ambos lados (archivo del usuario + carpeta destino del usuario).
- Compartir entre usuarios: **no soportado**. Cada cuenta es una isla.

---

## 12. Modo local vs host (admin)

- Se cambia desde `/admin/settings` con `PATCH /admin/settings/mode`.
- Persiste en `app_settings.mode` con caché forever.
- Efectos al cambiar a `local`:
  - `ProcessTranscriptionFile` usa `LocalProcessTranscriber` (subprocess Python).
  - `SummarizeTranscription` usa `OllamaSummarizer` (a menos que el usuario haya elegido Groq).
- Efectos al cambiar a `host`:
  - `ProcessTranscriptionFile` usa `RemoteApiTranscriber`.
  - `SummarizeTranscription` usa `RemoteSummarizer`.
  - Si el worker remoto cae, los jobs entran en `waiting_for_worker` y se reintentan cada 60s.
- ⚠️ Después de cambiar el modo (o cualquier config) **hay que reiniciar el queue worker**, porque tiene la config booteada en memoria.

---

## 13. Whisper timeout

- Default global: `WHISPER_TIMEOUT` env (default `1800` segundos / 30 min).
- Override por admin: `PATCH /admin/settings/whisper-timeout` lo guarda en `app_settings.whisper_timeout`.
- [AppSetting::whisperTimeout()](app/Models/AppSetting.php) devuelve el override si existe, sino el default de la config. `ProcessTranscriptionFile` lo lee para el `timeout` del job.

---

## 14. Flujos críticos resumidos

### Onboarding de un nuevo usuario

```
1. registro (email o Google)
2. ¿primer usuario? sí → admin + approved; no → user + pending
3. si pending: redirect a /account/pending hasta que un admin lo apruebe
4. admin entra a /admin/users y hace POST /admin/users/{id}/approve
5. el usuario refresca, ahora tiene acceso al dashboard
6. el admin opcionalmente le setea audio_limit
```

### Transcripción + resumen

```
1. user sube archivo en dashboard
2. POST /transcriptions: valida, almacena, crea row queued, dispatcha job
3. queue worker toma el job:
   a. status=processing (o enhancing si clean_audio)
   b. corre Whisper local o remote
   c. guarda Transcription + segments
   d. status=completed
4. user abre la página de show
5. opcional: edita el texto → reconcile de segments
6. opcional: pide resumen → SummarizeTranscription corre con Ollama o Groq
7. opcional: descarga TXT/SRT/PDF, mueve a carpeta, renombra
```

### Aprobación/rechazo administrativo

```
1. admin entra a /admin/users (listado de pending + approved)
2. /admin/users/{id}/approve → approved + approved_at=now
3. /admin/users/{id}/revoke → vuelve a pending (las transcripciones del usuario no se borran)
4. /admin/users/{id}/limit → setea audio_limit
5. /admin/users/{id}/role → admin/user
6. DELETE /admin/users/{id} → cascada total de los datos del usuario
```

---

## 15. Reglas de UI relevantes

- **Tema (claro/oscuro)**: persistente en `users.settings.theme`, sincronizado con `localStorage` desde `app.js`. Aplica clase `dark` al `<html>`.
- **Idioma del audio**: el usuario lo elige en el upload (default último usado en `localStorage.escribelo_last_language`). Whisper lo recibe como hint; igualmente detecta autom.
- **Polling de jobs**: en el dashboard cada N segundos, en `Transcriptions/Show` cada 2.5s solo cuando `summary_status ∈ {queued, processing}`.
- **Karaoke**: click en cualquier palabra del transcript reproduce desde el `start_seconds` del segmento, con un lead-in de 0.25s para que la palabra no quede recortada al inicio.
- **Confirm modal global**: acciones destructivas (eliminar archivo/carpeta, restaurar al original de Whisper, descartar cleaned, reemplazar original) usan `useConfirm` que devuelve `Promise<bool>`.
- **Toast global**: éxito/error de operaciones, además del flash de Inertia.

---

## 16. Edge cases conocidos

- **Audio con `clean_audio=true` cuyo denoise falla**: el job marca `failed` y no continúa con la transcripción. El usuario debe re-disparar sin denoise.
- **Worker remoto vivo pero lento**: el timeout del HTTP es `services.remote_worker.timeout` (default 14400s = 4h). Para audios muy largos puede no alcanzar.
- **Edición que vacía el texto entero**: `SegmentReconciler` devuelve `effective_segments = []`. El SRT queda vacío; el audio sigue reproduciendo pero sin highlight.
- **Resumen cancelado a último momento**: si el cancel llega después del HTTP exitoso al LLM, el job descarta el resultado pero los tokens ya fueron consumidos (y registrados en `groq_usage`).
- **Re-link de Google a otro email**: hoy no se permite cambiar el `google_id` de una cuenta una vez linkeada. Habría que desvincular vía DB.
- **Carpeta borrada con archivos adentro**: los archivos quedan con `transcription_folder_id = null` (van al feed "sin ordenar"). El usuario lo ve antes de confirmar.
