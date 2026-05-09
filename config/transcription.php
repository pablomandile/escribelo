<?php

return [
    'python' => env('WHISPER_PYTHON', 'python'),
    'model' => env('WHISPER_MODEL', 'small'),
    // 30 min por default — pensado para corridas en GPU. Subilo si transcribís
    // audios muy largos en CPU (con large-v3 sin GPU puede tardar varias horas).
    'timeout' => (int) env('WHISPER_TIMEOUT', 1800),
];
