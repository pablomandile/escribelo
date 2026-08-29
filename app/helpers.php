<?php

use App\Models\AppSetting;

if (! function_exists('escribelo_mode')) {
    /**
     * Returns the current global processing mode: 'local' or 'host'.
     * In 'local' mode, transcription/summarization runs as local subprocesses.
     * In 'host' mode, they are delegated to the remote worker via Cloudflare Tunnel.
     */
    function escribelo_mode(): string
    {
        $mode = AppSetting::get('mode', 'local');
        return in_array($mode, ['local', 'host'], true) ? $mode : 'local';
    }
}

if (! function_exists('escribelo_ini_bytes')) {
    /**
     * Convierte un valor shorthand de php.ini ("128M", "2G", "512K") a bytes.
     * Devuelve 0 cuando el valor está vacío o es 0 (= sin límite, caso de
     * post_max_size=0), para que quien lo use trate 0 como "no hay tope".
     */
    function escribelo_ini_bytes(string|false|null $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        $metric = strtoupper(substr($value, -1));

        return match ($metric) {
            'K' => $number * 1024,
            'M' => $number * 1048576,
            'G' => $number * 1073741824,
            default => $number,
        };
    }
}

if (! function_exists('escribelo_format_bytes')) {
    /** Bytes a un texto corto para mostrarle al usuario ("142 MB", "1,4 GB"). */
    function escribelo_format_bytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.').' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 0, ',', '.').' MB';
        }
        return number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}

if (! function_exists('escribelo_upload_limits')) {
    /**
     * Límites REALES de subida, leídos del PHP que está corriendo.
     *
     * El 413 "Content Too Large" que veíamos en Hostinger salía de acá: el
     * formulario permitía 500 MB por archivo y hasta 10 archivos, pero el
     * hosting compartido acepta un POST mucho más chico. Cuando el
     * Content-Length se pasa de post_max_size, PHP descarta el body y el
     * request muere antes de que corra ninguna validación nuestra.
     *
     * En vez de hardcodear un número que se desactualiza con cada cambio de
     * hPanel, publicamos lo que el server admite hoy y validamos contra eso
     * en el cliente y en el servidor.
     *
     * @return array{per_file: int, per_request: int, app_cap: int}
     */
    function escribelo_upload_limits(): array
    {
        // Tope propio de la app: 500 MB por archivo.
        $appCap = 512000 * 1024;

        $uploadMax = escribelo_ini_bytes(ini_get('upload_max_filesize'));
        $postMax = escribelo_ini_bytes(ini_get('post_max_size'));

        // 0 = sin límite en php.ini. El tope de la app siempre aplica.
        $perFile = min(...array_filter([$appCap, $uploadMax, $postMax]));

        // Margen para los otros campos del form y los boundaries del multipart:
        // el POST completo pesa un poco más que la suma de los archivos.
        $perRequest = $postMax > 0 ? max(0, $postMax - 262144) : $appCap * 10;

        return [
            'per_file' => (int) $perFile,
            'per_request' => (int) $perRequest,
            'app_cap' => $appCap,
        ];
    }
}
