@echo off
REM ============================================================
REM Escribelo - arranca el entorno de desarrollo en local.
REM Doble-click abre 3 ventanas de consola:
REM   1) Laravel server  -> http://127.0.0.1:8100
REM   2) Vite dev server -> puerto 8101 (HMR)
REM   3) Queue worker    -> procesa las transcripciones (Whisper)
REM Luego abre el navegador en http://127.0.0.1:8100
REM
REM Cerra una ventana (o Ctrl+C) para detener ese servicio.
REM ============================================================

set "PROJECT=C:\laragon\www\escribelo"
set "PHP_DIR=C:\laragon\bin\php\php-8.4.22-Win32-vs17-x64"
set "APP_PORT=8100"
set "VITE_PORT=8101"

REM Leer WHISPER_TIMEOUT del .env (fallback a 1800 si no esta definido).
set "WHISPER_TIMEOUT=1800"
for /F "tokens=2 delims==" %%a in ('findstr /B /R "^WHISPER_TIMEOUT=" "%PROJECT%\.env" 2^>nul') do set "WHISPER_TIMEOUT=%%a"

start "Escribelo - Laravel" cmd /k "cd /d %PROJECT% && set PATH=%PHP_DIR%;%%PATH%% && echo Laravel en http://127.0.0.1:%APP_PORT% && php artisan serve --port=%APP_PORT%"
start "Escribelo - Vite" cmd /k "cd /d %PROJECT% && echo Vite en puerto %VITE_PORT% && npm run dev -- --port=%VITE_PORT% --strictPort"
start "Escribelo - Queue" cmd /k "cd /d %PROJECT% && set PATH=%PHP_DIR%;%%PATH%% && echo Queue worker (timeout=%WHISPER_TIMEOUT%s) && php artisan queue:work --tries=1 --timeout=%WHISPER_TIMEOUT%"

REM Esperar unos segundos a que el server levante y abrir el navegador.
timeout /t 4 /nobreak >nul
start "" http://127.0.0.1:%APP_PORT%
