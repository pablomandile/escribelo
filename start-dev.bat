@echo off
REM ============================================================
REM Escribelo — arranca todos los servicios de desarrollo en local.
REM Doble-click este archivo y se abren 3 ventanas de cmd:
REM   1) Laravel server   (php artisan serve)
REM   2) Vite dev server  (npm run dev)
REM   3) Queue worker     (php artisan queue:work)
REM
REM Cerrá una ventana para detener su servicio. Ctrl+C funciona igual.
REM ============================================================

set "PROJECT=C:\laragon\www\escribelo"
set "PHP_DIR=C:\laragon\bin\php\php-8.4.21-Win32-vs17-x64"

REM Leer WHISPER_TIMEOUT del .env (fallback a 1800 si no está definido).
set "WHISPER_TIMEOUT=1800"
for /F "tokens=2 delims==" %%a in ('findstr /B /R "^WHISPER_TIMEOUT=" "%PROJECT%\.env" 2^>nul') do set "WHISPER_TIMEOUT=%%a"

start "Escribelo - Laravel" cmd /k "cd /d %PROJECT% && set PATH=%PHP_DIR%;%%PATH%% && echo Laravel en http://127.0.0.1:8001 && php artisan serve --port=8001"
start "Escribelo - Vite" cmd /k "cd /d %PROJECT% && npm run dev"
start "Escribelo - Queue" cmd /k "cd /d %PROJECT% && set PATH=%PHP_DIR%;%%PATH%% && echo Queue worker con timeout=%WHISPER_TIMEOUT%s && php artisan queue:work --tries=1 --timeout=%WHISPER_TIMEOUT%"
