<?php

namespace App\Services\Worker;

use Illuminate\Support\Facades\Storage;

class QueueWorkerManager
{
    private const PID_FILENAME = 'worker/queue.pid';
    private const LOG_FILENAME = 'worker/queue.log';

    public function status(): array
    {
        $managedPid = $this->readPid();
        $managedAlive = $managedPid !== null && $this->isPidAlive($managedPid);

        // También detectamos workers que el admin arrancó manualmente desde una terminal
        // (no por nuestro panel). Eso evita "spawnear duplicado" y muestra estado real.
        $externalPids = $this->findExternalQueueWorkers();
        if ($managedAlive && in_array($managedPid, $externalPids, true)) {
            $externalPids = array_values(array_diff($externalPids, [$managedPid]));
        }

        return [
            'running' => $managedAlive || ! empty($externalPids),
            'managed' => $managedAlive,
            'managed_pid' => $managedAlive ? $managedPid : null,
            'external_pids' => $externalPids,
            'log_tail' => $this->readLogTail(),
            'pending_jobs' => (int) \DB::table('jobs')->count(),
            'failed_jobs' => (int) \DB::table('failed_jobs')->count(),
        ];
    }

    public function start(): array
    {
        $st = $this->status();
        if ($st['running']) {
            return ['ok' => true, 'message' => 'Ya hay un queue worker corriendo.'];
        }

        Storage::disk('local')->makeDirectory('worker');
        @unlink($this->logPath());

        // Usamos el mismo PHP que está corriendo este request — garantía de que es el de Laragon 8.4.
        $php = PHP_BINARY;
        $cwd = base_path();
        $artisan = base_path('artisan');
        $logFile = $this->logPath();

        $env = [
            'PATH' => (string) getenv('PATH'),
            'SYSTEMROOT' => (string) (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
            'USERPROFILE' => (string) getenv('USERPROFILE'),
            'APPDATA' => (string) getenv('APPDATA'),
            'LOCALAPPDATA' => (string) getenv('LOCALAPPDATA'),
        ];

        if ($this->isWindows()) {
            $this->spawnWindows($php, $artisan, $logFile, $cwd, $env);
        } else {
            $this->spawnUnix($php, $artisan, $logFile, $cwd, $env);
        }

        // Esperamos hasta 6s a que el worker arranque y empezamos a verlo en tasklist.
        $foundPid = null;
        for ($i = 0; $i < 30; $i++) {
            usleep(200_000);
            $candidates = $this->findExternalQueueWorkers();
            if (! empty($candidates)) {
                $foundPid = $candidates[0];
                break;
            }
        }

        if ($foundPid === null) {
            $tail = $this->readLogTail(2000);
            throw new \RuntimeException("El queue worker no terminó de arrancar.\nLog:\n".$tail);
        }

        // Guardamos su PID para que `stop()` pueda matarlo después.
        file_put_contents($this->pidPath(), (string) $foundPid);

        return ['ok' => true, 'pid' => $foundPid, 'message' => 'Queue worker arriba.'];
    }

    public function stop(): array
    {
        $managedPid = $this->readPid();
        $external = $this->findExternalQueueWorkers();

        $killed = [];
        // Matamos primero el "nuestro", después cualquier otro que detectemos.
        foreach (array_unique(array_filter([$managedPid, ...$external])) as $pid) {
            if ($this->isPidAlive($pid)) {
                $this->killPid($pid);
                $killed[] = $pid;
            }
        }

        @unlink($this->pidPath());

        if (empty($killed)) {
            return ['ok' => true, 'message' => 'No había queue worker corriendo.'];
        }

        return ['ok' => true, 'message' => 'Detenidos: '.implode(', ', $killed), 'pids' => $killed];
    }

    public function restart(): array
    {
        $this->stop();
        // Pequeña espera para que el OS libere el PID y/o lock de DB.
        usleep(500_000);
        return $this->start();
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    private function spawnWindows(string $php, string $artisan, string $logFile, string $cwd, array $env): void
    {
        @file_put_contents($logFile, '');
        $launcher = Storage::disk('local')->path('worker/launch_queue.bat');
        $content = "@echo off\r\n"
            ."start \"escribelo-queue\" /B \"".$php."\" \"".$artisan."\" queue:work --tries=1 --timeout=14400 > \"".$logFile."\" 2>&1\r\n";
        file_put_contents($launcher, $content);

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];
        $proc = proc_open(['cmd.exe', '/c', $launcher], $descriptors, $pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar queue worker.');
        }
        proc_close($proc);
    }

    private function spawnUnix(string $php, string $artisan, string $logFile, string $cwd, array $env): void
    {
        $cmd = sprintf(
            'nohup %s %s queue:work --tries=1 --timeout=14400 > %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($logFile),
        );
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open(['sh', '-c', $cmd], $descriptors, $pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar queue worker.');
        }
        proc_close($proc);
    }

    /**
     * Devuelve PIDs de cualquier proceso PHP cuyo command line contenga "queue:work".
     * Importante en Windows porque puede haber sido arrancado manualmente desde otra
     * terminal y no pasamos por nuestra spawn.
     *
     * @return int[]
     */
    private function findExternalQueueWorkers(): array
    {
        if ($this->isWindows()) {
            // wmic está deprecado en versiones nuevas pero sigue presente. Si falla,
            // caemos a tasklist /v y filtramos por window title.
            $output = [];
            @exec('wmic process where "name=\'php.exe\'" get ProcessId,CommandLine /format:csv 2>NUL', $output);
            $pids = [];
            foreach ($output as $line) {
                if (! str_contains($line, 'queue:work')) {
                    continue;
                }
                // CSV: Node,CommandLine,ProcessId
                $parts = str_getcsv($line, ',', '"', '\\');
                $pid = end($parts);
                if (ctype_digit(trim((string) $pid))) {
                    $pids[] = (int) trim($pid);
                }
            }
            return array_values(array_unique($pids));
        }

        $output = [];
        @exec("ps -eo pid,args | grep 'queue:work' | grep -v grep | awk '{print $1}'", $output);
        return array_values(array_unique(array_map('intval', array_filter($output, 'is_numeric'))));
    }

    private function readPid(): ?int
    {
        $path = $this->pidPath();
        if (! is_file($path)) return null;
        $contents = trim((string) @file_get_contents($path));
        return ctype_digit($contents) ? (int) $contents : null;
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
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
        if (! is_file($path)) return '';
        $size = @filesize($path);
        if (! $size) return '';
        $offset = max(0, $size - $maxBytes);
        $fp = @fopen($path, 'rb');
        if (! $fp) return '';
        try {
            fseek($fp, $offset);
            $raw = (string) fread($fp, $maxBytes);
        } finally {
            fclose($fp);
        }
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;
        if (mb_check_encoding($stripped, 'UTF-8')) return $stripped;
        return @mb_convert_encoding($stripped, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8') ?: $stripped;
    }

    private function pidPath(): string  { return Storage::disk('local')->path(self::PID_FILENAME); }
    private function logPath(): string  { return Storage::disk('local')->path(self::LOG_FILENAME); }
    private function isWindows(): bool  { return DIRECTORY_SEPARATOR === '\\'; }
}
