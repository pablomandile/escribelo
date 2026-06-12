# Plan: Dockerizar Escribelo (PHP + MySQL) con Whisper y Ollama en el host

> Documento de planificación para revisar más tarde. Todavía NO implementado.

## Contexto

Hoy el proyecto corre sobre Laragon, lo que ata la app a la versión de PHP instalada
en el sistema (ya tuvimos el problema 8.3 vs 8.4) y a la configuración manual de MySQL.
El objetivo es **mover PHP y MySQL a Docker** para tener un entorno reproducible e
independiente de Laragon y del versionado de PHP del host.

Decisiones tomadas:
- El proyecto corre **siempre en local, en esta PC, un solo usuario**.
- **Whisper (faster-whisper), ffmpeg y Ollama quedan en el host** — ya están instalados
  (venv en `whisper_worker/.venv`, ffmpeg en PATH, Ollama en `localhost:11434`). No se
  duplican dentro de Docker.

Esto define la arquitectura: la app usa el **modo "host"** del propio proyecto. El PHP
dentro del contenedor no spawnea Python; le pega por HTTP al worker FastAPI que corre en
el host (`whisper_worker/api_server.py`). El worker transcribe con faster-whisper y, para
resúmenes, llama a Ollama directamente. Verificado en el código (sin cambios necesarios):
- `app/Services/Transcriber/RemoteApiTranscriber.php:20-32` → usa `REMOTE_WORKER_URL` + token, health check + POST `/transcribe`.
- `app/Services/Summarizer/RemoteSummarizer.php:15-27` → POST `/summarize` con token.
- `whisper_worker/api_server.py:58-69,209-259` → auth Bearer `ESCRIBELO_REMOTE_TOKEN`; `/summarize` lee `OLLAMA_BASE_URL` y `OLLAMA_SUMMARY_MODEL` del entorno del worker.
- El switch local/host se resuelve por `AppSetting::get('mode')` (`app/Jobs/ProcessTranscriptionFile.php` y `SummarizeTranscription.php`).

**No se requieren cambios de código PHP/Python.** Solo archivos Docker + variables de entorno + un launcher del worker en el host.

## Respuesta directa: ¿cuántas imágenes?

- **1 imagen que construimos nosotros**: la app PHP (PHP 8.4 + extensiones + dependencias
  Composer + assets del frontend ya compilados). Esta misma imagen corre 2 contenedores:
  el **web** y el **queue worker**.
- **1 imagen oficial que solo se baja**: `mysql:8.4`.
- **Whisper y Ollama NO son imágenes** → quedan en el host.

O sea: "una para MySQL y otra para PHP" es correcto. Total: **2 imágenes, ~3 contenedores**
(web, queue, mysql), más el worker de Whisper y Ollama corriendo en el host.

## Qué instalar en la PC

- **Docker Desktop para Windows** (backend WSL2). Es lo único nuevo.
- Se puede dejar de usar Laragon para este proyecto (puede seguir para otros).
- Whisper venv, ffmpeg y Ollama ya están instalados — se reutilizan.

## Serving elegido

**FrankenPHP** en modo clásico (un solo contenedor sirve estáticos + PHP). Es la opción
más simple y robusta para single-user local; mejor que `php artisan serve` para servir
audio + polling concurrentes, y sin el contenedor extra de nginx. Base: `dunglas/frankenphp:php8.4`.

## Archivos a crear

### 1. `Dockerfile` (multi-stage)
```dockerfile
# Stage 1 — build frontend
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build            # genera public/build/manifest.json

# Stage 2 — app PHP + FrankenPHP
FROM dunglas/frankenphp:php8.4 AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
      pdo_mysql mbstring gd zip intl bcmath exif pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction
COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "php-server", "-r", "public/", "--listen", ":80"]
```
Nota: la imagen **NO** instala Python ni ffmpeg (viven en el host).

### 2. `docker-compose.yml`
- `mysql`: imagen `mysql:8.4`, volumen `mysql-data`, env (DB name/user/pass), healthcheck.
- `app`: build del Dockerfile, `ports: "8100:80"`, `env_file: .env`, `depends_on: mysql`,
  `volumes: ./storage/app:/app/storage/app` (persistir audios/cleaned/transcripts),
  `extra_hosts: host.docker.internal:host-gateway`.
- `queue`: misma imagen (`build` reusado), `command: php artisan queue:work --tries=1 --timeout=1800`,
  mismo `env_file`, mismo volumen de storage, mismo `extra_hosts`, `depends_on: mysql`.

### 3. `docker/entrypoint.sh`
- Espera a que MySQL acepte conexiones.
- `php artisan migrate --force` (primera vez `--seed`).
- `php artisan config:cache && route:cache && view:cache`.
- Asegura permisos de `storage/`.
- `exec "$@"` para lanzar el CMD (FrankenPHP o queue:work según el servicio).

### 4. `.dockerignore`
Excluir: `node_modules`, `vendor`, `.git`, `storage/app/audios`, `storage/app/cleaned`,
`storage/app/transcripts`, `storage/logs/*`, `whisper_worker/.venv`, `public/build`
(se regenera en el stage frontend), `.env`.

### 5. `start-worker-host.bat` (corre en el HOST, no en Docker)
Levanta el worker FastAPI usando el venv ya instalado:
```bat
set "ESCRIBELO_REMOTE_TOKEN=<token-secreto-compartido>"
set "OLLAMA_BASE_URL=http://localhost:11434"
set "OLLAMA_SUMMARY_MODEL=llama3.2:3b"   REM o el modelo chico que bajes
"C:\laragon\www\escribelo\whisper_worker\.venv\Scripts\python.exe" -m uvicorn whisper_worker.api_server:app --host 0.0.0.0 --port 8765
```
`--host 0.0.0.0` para que el contenedor lo alcance vía `host.docker.internal`.

## Cambios de configuración (.env del contenedor)

```
APP_URL=http://localhost:8100
DB_HOST=mysql
DB_DATABASE=escribelo
DB_USERNAME=escribelo
DB_PASSWORD=<pass>
# Modo host: PHP delega en el worker del host
REMOTE_WORKER_URL=http://host.docker.internal:8765
REMOTE_WORKER_TOKEN=<mismo token que ESCRIBELO_REMOTE_TOKEN>
REMOTE_WORKER_MANAGE_LOCALLY=false   # el contenedor NO gestiona el proceso del worker
OLLAMA_BASE_URL=http://host.docker.internal:11434   # solo para el probe de estado del admin
```
Además, poner la app en **modo host**: desde `/admin/settings` (switch local→host) o
seteando `app_settings.mode='host'`. Mantener `QUEUE/SESSION/CACHE=database` (sin Redis).

## Migración de datos (opcional)

El usuario que ya se creó vive en el MySQL de Laragon. Dos opciones:
- **Empezar limpio**: el entrypoint corre `migrate --seed` y te registrás de nuevo (primer
  usuario = admin automático).
- **Conservar datos**: `mysqldump` de la base `escribelo` en Laragon e importarla al
  contenedor MySQL una vez levantado.

## Verificación end-to-end

1. `docker compose build` y `docker compose up -d`.
2. Ver logs: el entrypoint corre migraciones sin error; `app` y `queue` quedan "healthy".
3. Abrir `http://localhost:8100` → carga la app (HTTP 200), login/registro funciona.
4. En el host: doble-click `start-worker-host.bat`; en `/admin/settings` cambiar a modo
   "host" → el health check del worker debe dar OK (verde).
5. Subir un audio → el job (queue container) sube el archivo al worker host → transcribe en
   CPU `int8` → vuelve el JSON y se ve la transcripción. Probar export TXT/SRT/PDF.
6. Generar resumen → el worker host llama a Ollama (modelo configurado) → resumen OK.

## Caveats

- Hay que mantener **dos cosas corriendo**: `docker compose up` y el `start-worker-host.bat`.
  Más adelante se puede convertir el worker en servicio de Windows (NSSM) para que arranque solo.
- `host.docker.internal` funciona en Docker Desktop/Windows out-of-the-box; en compose se
  agrega `extra_hosts: host.docker.internal:host-gateway` por las dudas.
- Sin GPU: el worker host transcribe en CPU `int8` (ya es el caso actual).
- Firewall de Windows: puede pedir permiso para que el contenedor alcance el puerto 8765 del host.
- Los botones de "arrancar/parar worker" del panel admin no gestionan el proceso del host
  (`REMOTE_WORKER_MANAGE_LOCALLY=false`); el worker se arranca con el `.bat`.
