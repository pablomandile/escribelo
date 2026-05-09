<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $file->original_name }}</title>
    <style>
        @page { margin: 1.6cm 1.4cm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            color: #1f2937;
            line-height: 1.55;
        }
        h1 {
            font-size: 18pt;
            margin: 0 0 6px;
            color: #0f172a;
        }
        .meta {
            font-size: 9pt;
            color: #64748b;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .meta span { margin-right: 14px; }
        h2 {
            font-size: 13pt;
            margin: 22px 0 8px;
            color: #0f172a;
        }
        p.text { text-align: justify; white-space: pre-wrap; }
    </style>
</head>
<body>
    <h1>{{ $file->original_name }}</h1>
    <div class="meta">
        @if ($file->language)<span>Idioma: <strong>{{ strtoupper($file->language) }}</strong></span>@endif
        @if ($file->model)<span>Modelo: <strong>{{ $file->model }}</strong></span>@endif
        @if ($file->duration_seconds)<span>Duración: <strong>{{ gmdate('H:i:s', (int) $file->duration_seconds) }}</strong></span>@endif
        @if ($file->processed_at)<span>Procesado: <strong>{{ $file->processed_at->format('d/m/Y H:i') }}</strong></span>@endif
    </div>

    <h2>Transcripción</h2>
    <p class="text">{{ trim($transcription->effectiveText()) }}</p>
</body>
</html>
