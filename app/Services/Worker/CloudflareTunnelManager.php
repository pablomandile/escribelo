<?php

namespace App\Services\Worker;

use Illuminate\Support\Facades\Storage;

class CloudflareTunnelManager
{
    private const PID_FILENAME = 'worker/cloudflared.pid';
    private const LOG_FILENAME = 'worker/cloudflared.log';

    public function isManageable(): bool
    {
        return (bool) config('services.cloudflared.manage_locally', true);
    }

    public function status(): array
    {
        $pid = $this->readPid();
        $alive = $pid !== null && $this->isPidAlive($pid);

        return [
            'manageable' => $this->isManageable(),
            'binary_available' => $this->isBinaryAvailable(),
            'tunnel' => $this->tunnelName(),
            'running' => $alive,
            'pid' => $alive ? $pid : null,
            'log_tail' => $this->readLogTail(),
        ];
    }

    public function start(): array
    {
        if (! $this->isManageable()) {
            throw new \RuntimeException('La gestión local de cloudflared está deshabilitada.');
        }

        if ($this->status()['running']) {
            return ['ok' => true, 'message' => 'cloudflared ya estaba corriendo.', 'pid' => $this->readPid()];
        }

        if (! $this->isBinaryAvailable()) {
            throw new \RuntimeException(
                'No se encontró el binario `cloudflared`. Instalalo (winget install Cloudflare.cloudflared) '
                .'o configurá CLOUDFLARED_BIN en el .env apuntando a la ruta absoluta.'
            );
        }

        Storage::disk('local')->makeDirectory('worker');
        @unlink($this->logPath());
        @unlink($this->pidPath());

        $binary = $this->binary();
        $tunnel = $this->tunnelName();
        $cwd = base_path();

        $env = [
            'PATH' => (string) getenv('PATH'),
            'SYSTEMROOT' => (string) getenv('SYSTEMROOT'),
            'USERPROFILE' => (string) getenv('USERPROFILE'),
            'APPDATA' => (string) getenv('APPDATA'),
            'LOCALAPPDATA' => (string) getenv('LOCALAPPDATA'),
            'HOME' => (string) (getenv('HOME') ?: getenv('USERPROFILE')),
        ];

        if ($this->isWindows()) {
            $pid = $this->spawnWindows($binary, $tunnel, $this->logPath(), $cwd, $env);
        } else {
            $pid = $this->spawnUnix($binary, $tunnel, $this->logPath(), $cwd, $env);
        }

        if ($pid === null) {
            throw new \RuntimeException('No pude obtener el PID de cloudflared al lanzarlo.');
        }

        // Escribimos el PID para nuestro propio control. cloudflared no lo escribe solo.
        file_put_contents($this->pidPath(), (string) $pid);

        // Esperar 2s y revisar que siga vivo (si murió enseguida, hay error de config).
        usleep(2_000_000);
        if (! $this->isPidAlive($pid)) {
            $tail = $this->readLogTail(2000);
            @unlink($this->pidPath());
            throw new \RuntimeException("cloudflared arrancó y murió. Log:\n".$tail);
        }

        return ['ok' => true, 'pid' => $pid, 'message' => 'cloudflared arriba.'];
    }

    public function stop(): array
    {
        $pid = $this->readPid();

        if ($pid === null || ! $this->isPidAlive($pid)) {
            @unlink($this->pidPath());
            return ['ok' => true, 'message' => 'cloudflared no estaba corriendo.'];
        }

        $this->killPid($pid);

        for ($i = 0; $i < 25; $i++) {
            if (! $this->isPidAlive($pid)) {
                break;
            }
            usleep(200_000);
        }

        @unlink($this->pidPath());

        return ['ok' => true, 'message' => 'cloudflared detenido.', 'pid' => $pid];
    }

    public function restart(): array
    {
        $this->stop();
        return $this->start();
    }

    // ----------------------------------------------------------------------
    // Spawn
    // ----------------------------------------------------------------------

    private function spawnWindows(string $binary, ?string $tunnel, string $logFile, string $cwd, array $env): ?int
    {
        // Spawn directo (sin batch wrapper) porque necesitamos el PID exacto del proceso
        // cloudflared, no del cmd. Con `bypass_shell + create_new_console` el child queda
        // en su propia consola y sobrevive al fin del request PHP.
        $args = [$binary, 'tunnel'];
        if ($tunnel) {
            $args[] = 'run';
            $args[] = $tunnel;
        } else {
            $args[] = 'run';  // usa config por defecto
        }

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'w'],
        ];

        $proc = proc_open($args, $descriptors, $pipes, $cwd, $env, [
            'bypass_shell' => true,
            'create_new_console' => true,
        ]);

        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar cloudflared.');
        }

        $status = proc_get_status($proc);
        $pid = $status['pid'] ?? null;

        // No cerramos el handle aquí — proc_close puede bloquear o killear el child
        // según las condiciones. El handle se libera al fin del request.
        return $pid ? (int) $pid : null;
    }

    private function spawnUnix(string $binary, ?string $tunnel, string $logFile, string $cwd, array $env): ?int
    {
        $args = [$binary, 'tunnel', 'run'];
        if ($tunnel) {
            $args[] = $tunnel;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'w'],
        ];

        $proc = proc_open($args, $descriptors, $pipes, $cwd, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open() falló al lanzar cloudflared.');
        }

        $status = proc_get_status($proc);
        return isset($status['pid']) ? (int) $status['pid'] : null;
    }

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

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

    private function toUtf8(string $raw): string
    {
        $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;

        if (mb_check_encoding($stripped, 'UTF-8')) {
            return $stripped;
        }
        $converted = @mb_convert_encoding($stripped, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        return $converted ?: mb_convert_encoding($stripped, 'UTF-8', 'UTF-8');
    }

    private function pidPath(): string
    {
        return Storage::disk('local')->path(self::PID_FILENAME);
    }

    private function logPath(): string
    {
        return Storage::disk('local')->path(self::LOG_FILENAME);
    }

    private function binary(): string
    {
        $configured = (string) config('services.cloudflared.binary', '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        if ($this->isWindows()) {
            $resolved = trim((string) @shell_exec('where cloudflared 2>NUL'));
            if ($resolved !== '') {
                $first = strtok($resolved, "\r\n");
                if ($first && is_file($first)) {
                    return $first;
                }
            }
        }

        return 'cloudflared';
    }

    private function tunnelName(): ?string
    {
        $name = (string) config('services.cloudflared.tunnel', '');
        return $name !== '' ? $name : null;
    }

    private function isBinaryAvailable(): bool
    {
        $binary = $this->binary();

        if ($binary !== 'cloudflared' && is_file($binary)) {
            return true;
        }

        if ($this->isWindows()) {
            $resolved = trim((string) @shell_exec('where cloudflared 2>NUL'));
            return $resolved !== '';
        }

        $resolved = trim((string) @shell_exec('command -v cloudflared 2>/dev/null'));
        return $resolved !== '';
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }
}
