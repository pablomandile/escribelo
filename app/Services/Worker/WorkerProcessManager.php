<?php

namespace App\Services\Worker;

use Illuminate\Support\Facades\Storage;

class WorkerProcessManager
{
    private const PID_FILENAME = 'worker/worker.pid';
    private const LOG_FILENAME = 'worker/worker.log';

    public function isManageable(): bool
    {
        return (bool) config('services.remote_worker.manage_locally', true);
    }

    public function status(): array
    {
        $pid = $this->readPid();
        $alive = $pid !== null && $this->isPidAlive($pid);

        return [
            'manageable' => $this->isManageable(),
            'running' => $alive,
            'pid' => $alive ? $pid : null,
            'host' => $this->host(),
            'port' => $this->port(),
            'log_tail' => $this->readLogTail(),
        ];
    }

    public function start(): array
    {
        if (! $this->isManageable()) {
            throw new \RuntimeException('La gestión local del worker está deshabilitada en este entorno.');
        }

        if ($this->status()['running']) {
            return ['ok' => true, 'message' => 'El worker ya estaba corriendo.', 'pid' => $this->readPid()];
        }

        // Detectar orfanato: algo está escuchando en el puerto pero no tenemos PID file.
        // Probable causa: instancia previa que perdió su PID file al ser killed externamente.
        if ($this->isPortInUse()) {
            throw new \RuntimeException(
                "El puerto {$this->port()} ya está en uso por otro proceso (probablemente un worker anterior). "
                ."Cerrá esa instancia manualmente o reiniciá Laragon antes de levantar uno nuevo."
            );
        }

        $token = (string) config('services.remote_worker.token');
        if ($token === '') {
            throw new \RuntimeException('REMOTE_WORKER_TOKEN no está configurado en el .env.');
        }

        // Asegurar que el directorio del worker existe (PID + log).
        Storage::disk('local')->makeDirectory('worker');
        // Limpiar log viejo para que el tail muestre solo el run actual.
        @file_put_contents($this->logPath(), '');

        $python = $this->pythonBinary();
        $host = $this->host();
        $port = $this->port();
        $pidFile = $this->pidPath();
        $logFile = $this->logPath();
        $cwd = base_path();

        // Variables de entorno necesarias para el FastAPI.
        // PYTHONUSERBASE asegura que Python encuentre los paquetes user-installed
        // (faster-whisper, ctranslate2, etc.) cuando Apache corre como otro usuario.
        $appData = getenv('APPDATA') ?: ($_SERVER['APPDATA'] ?? '');
        $userProfile = getenv('USERPROFILE') ?: ($_SERVER['USERPROFILE'] ?? '');
        if ($appData === '' && $userProfile !== '') {
            $appData = $userProfile.'\\AppData\\Roaming';
        }
        if ($appData === '') {
            $appData = 'C:\\Users\\'.(getenv('USERNAME') ?: 'pghm').'\\AppData\\Roaming';
        }

        $env = [
            'ESCRIBELO_REMOTE_TOKEN' => $token,
            'ESCRIBELO_PID_FILE' => $pidFile,
            'OLLAMA_BASE_URL' => (string) config('services.ollama.base_url', 'http://localhost:11434'),
            'OLLAMA_SUMMARY_MODEL' => (string) config('services.ollama.summary_model', 'gemma3:12b'),
            'PYTHONIOENCODING' => 'utf-8',
            'PYTHONUSERBASE' => $appData.'\\Python',
            'PATH' => (string) getenv('PATH'),
            'SYSTEMROOT' => (string) (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
            'USERPROFILE' => $userProfile ?: 'C:\\Users\\'.(getenv('USERNAME') ?: 'pghm'),
            'APPDATA' => $appData,
            'LOCALAPPDATA' => (string) getenv('LOCALAPPDATA'),
        ];

        if ($this->isWindows()) {
            $this->spawnWindows($python, $host, $port, $logFile, $cwd, $env);
        } else {
            $this->spawnUnix($python, $host, $port, $logFile, $cwd, $env);
        }

        // Esperar hasta 8 segundos a que el FastAPI escriba el PID file.
        $pid = null;
        for ($i = 0; $i < 40; $i++) {
            usleep(200_000);
            $pid = $this->readPid();
            if ($pid !== null) {
                break;
            }
        }

        if ($pid === null) {
            $tail = $this->readLogTail(2000);
            throw new \RuntimeException("El worker no terminó de arrancar.\nLog:\n".$tail);
        }

        return ['ok' => true, 'pid' => $pid, 'message' => 'Worker arriba.'];
    }

    public function stop(): array
    {
        $pid = $this->readPid();

        if ($pid === null || ! $this->isPidAlive($pid)) {
            // Si quedó un PID file huérfano, lo limpiamos.
            @unlink($this->pidPath());
            return ['ok' => true, 'message' => 'El worker no estaba corriendo.'];
        }

        $this->killPid($pid);

        // Esperar hasta 5s a que el proceso termine y libere el PID file.
        for ($i = 0; $i < 25; $i++) {
            if (! $this->isPidAlive($pid)) {
                break;
            }
            usleep(200_000);
        }

        @unlink($this->pidPath());

        return ['ok' => true, 'message' => 'Worker detenido.', 'pid' => $pid];
    }

    public function restart(): array
    {
        $this->stop();
        return $this->start();
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function spawnWindows(string $python, string $host, int $port, string $logFile, string $cwd, array $env): void
    {
        // Estrategia: escribimos un .bat helper que usa `start /B` (la forma idiomática
        // de Windows para desacoplar un proceso). El .bat se encarga de la redirección
        // del log; PHP NO lo abre en proc_open para evitar el doble-lock de Windows.
        @unlink($logFile);

        $launcher = Storage::disk('local')->path('worker/launch_worker.bat');
        $launcherContent = "@echo off\r\n"
            ."start \"escribelo-worker\" /B \"".$python."\" -m uvicorn whisper_worker.api_server:app --host ".$host." --port ".$port." > \"".$logFile."\" 2>&1\r\n";
        file_put_contents($launcher, $launcherContent);

        // Importante: stdout y stderr van a NUL en el lado de PHP. La redirección real
        // al log la hace el .bat con `>` (truncate). Si los dos abren el mismo archivo,
        // Windows da "El proceso no tiene acceso al archivo".
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];

        $proc = proc_open(['cmd.exe', '/c', $launcher], $descriptors, $pipes, $cwd, $env);

        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar el worker.');
        }

        // start /B retorna en milisegundos. Esperamos al cmd wrapper (no al uvicorn).
        proc_close($proc);
    }

    private function spawnUnix(string $python, string $host, int $port, string $logFile, string $cwd, array $env): void
    {
        $command = sprintf(
            'nohup %s -m uvicorn whisper_worker.api_server:app --host %s --port %d > %s 2>&1 &',
            escapeshellarg($python),
            escapeshellarg($host),
            $port,
            escapeshellarg($logFile),
        );

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = proc_open(['sh', '-c', $command], $descriptors, $pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar el worker.');
        }
        proc_close($proc);
    }

    private function readPid(): ?int
    {
        $path = $this->pidPath();
        if (! is_file($path)) {
            return null;
        }
        $contents = trim((string) @file_get_contents($path));
        return ctype_digit($contents) ? (int) $contents : null;
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if ($this->isWindows()) {
            $output = [];
            @exec('tasklist /FI "PID eq '.$pid.'" /FO CSV /NH 2>NUL', $output);
            foreach ($output as $line) {
                if (str_contains($line, '"'.$pid.'"')) {
                    return true;
                }
            }
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function killPid(int $pid): void
    {
        if ($this->isWindows()) {
            @exec('taskkill /F /PID '.$pid.' /T 2>NUL');
        } else {
            @posix_kill($pid, SIGTERM);
        }
    }

    private function readLogTail(int $maxBytes = 4000): string
    {
        $path = $this->logPath();
        if (! is_file($path)) {
            return '';
        }
        $size = @filesize($path);
        if (! $size) {
            return '';
        }
        $offset = max(0, $size - $maxBytes);
        $fp = @fopen($path, 'rb');
        if (! $fp) {
            return '';
        }
        try {
            fseek($fp, $offset);
            $raw = (string) fread($fp, $maxBytes);
        } finally {
            fclose($fp);
        }

        return $this->toUtf8($raw);
    }

    /**
     * El log de uvicorn en Windows suele venir en CP1252 con códigos ANSI de color.
     * Normalizamos a UTF-8 limpio para que JsonResponse no explote.
     */
    private function toUtf8(string $raw): string
    {
        // Strip códigos ANSI (\x1b[...m) que ensucian el log y rompen el render.
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;

        if (mb_check_encoding($stripped, 'UTF-8')) {
            return $stripped;
        }

        $converted = @mb_convert_encoding($stripped, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        if ($converted === false || $converted === '') {
            // Último recurso: descartar bytes inválidos.
            return mb_convert_encoding($stripped, 'UTF-8', 'UTF-8');
        }
        return $converted;
    }

    private function pidPath(): string
    {
        return Storage::disk('local')->path(self::PID_FILENAME);
    }

    private function logPath(): string
    {
        return Storage::disk('local')->path(self::LOG_FILENAME);
    }

    private function pythonBinary(): string
    {
        // Prioridad: REMOTE_WORKER_PYTHON > WHISPER_PYTHON > resolver desde PATH > 'python'.
        // Apache/PHP-FPM puede tener un PATH distinto al del shell del usuario, por eso
        // conviene apuntar absoluto al python que tiene faster-whisper instalado.
        foreach ([
            (string) config('services.remote_worker.python', ''),
            (string) config('transcription.python', ''),
        ] as $candidate) {
            if ($candidate !== '' && $candidate !== 'python' && is_file($candidate)) {
                return $candidate;
            }
        }

        if ($this->isWindows()) {
            $resolved = trim((string) @shell_exec('where python 2>NUL'));
            if ($resolved !== '') {
                $first = strtok($resolved, "\r\n");
                if ($first && is_file($first)) {
                    return $first;
                }
            }
        }

        return 'python';
    }

    private function host(): string
    {
        return (string) config('services.remote_worker.host', '127.0.0.1');
    }

    private function port(): int
    {
        return (int) config('services.remote_worker.port', 8765);
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    private function isPortInUse(): bool
    {
        $sock = @fsockopen($this->host(), $this->port(), $errno, $errstr, 0.4);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
