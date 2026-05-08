<?php

return [
    'python' => env('WHISPER_PYTHON', 'python'),
    'model' => env('WHISPER_MODEL', 'small'),
    'timeout' => (int) env('WHISPER_TIMEOUT', 3600),
];
