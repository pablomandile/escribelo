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
