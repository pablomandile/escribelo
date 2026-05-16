<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTranscriptionFile;
use App\Models\TranscriptionFile;
use App\Models\TranscriptionFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mime\MimeTypes;

class TranscriptionFileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $folderId = $request->query('folder');
        $folderId = is_numeric($folderId) ? (int) $folderId : null;
        $filter = $request->query('filter') === 'unfiled' ? 'unfiled' : 'recent';

        $activeFolder = null;
        if ($folderId !== null) {
            $folder = TranscriptionFolder::whereBelongsTo($user)
                ->with('parent:id,name')
                ->find($folderId);
            if (! $folder) {
                $folderId = null;
            } else {
                $activeFolder = [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'parent' => $folder->parent ? [
                        'id' => $folder->parent->id,
                        'name' => $folder->parent->name,
                    ] : null,
                ];
            }
        }

        $filesQuery = TranscriptionFile::query()
            ->with(['folder.parent', 'transcription'])
            ->whereBelongsTo($user);

        if ($folderId !== null) {
            $filesQuery->where('transcription_folder_id', $folderId)
                ->orderByRaw('LOWER(original_name) ASC');
        } elseif ($filter === 'unfiled') {
            $filesQuery->whereNull('transcription_folder_id')->latest();
        } else {
            $filesQuery->latest()->limit(20);
        }

        $files = $filesQuery->get()
            ->map(fn (TranscriptionFile $file) => $this->serializeFile($file));

        $folders = TranscriptionFolder::query()
            ->whereBelongsTo($user)
            ->whereNull('parent_id')
            ->with(['children' => function ($query) {
                $query->withCount('files')->orderBy('name');
            }])
            ->withCount('files')
            ->orderBy('name')
            ->get()
            ->map(fn (TranscriptionFolder $folder) => [
                'id' => $folder->id,
                'name' => $folder->name,
                'files_count' => $folder->files_count,
                'children' => $folder->children->map(fn (TranscriptionFolder $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'files_count' => $child->files_count,
                ])->all(),
            ]);

        $stats = [
            'total' => TranscriptionFile::whereBelongsTo($user)->count(),
            'queued' => TranscriptionFile::whereBelongsTo($user)->whereIn('status', ['queued', 'processing'])->count(),
            'completed' => TranscriptionFile::whereBelongsTo($user)->where('status', 'completed')->count(),
            'failed' => TranscriptionFile::whereBelongsTo($user)->where('status', 'failed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'files' => $files,
            'folders' => $folders,
            'stats' => $stats,
            'filter' => $folderId !== null ? 'folder' : $filter,
            'activeFolderId' => $folderId,
            'activeFolder' => $activeFolder,
            'availableModels' => ['small', 'medium', 'large-v3'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['required', 'file', 'max:512000', 'mimes:mp3,wav,m4a,mp4,webm,ogg,oga,flac,aac'],
            'transcription_folder_id' => ['nullable', 'integer', 'exists:transcription_folders,id'],
            'model' => ['required', 'string', 'in:tiny,base,small,medium,large,large-v2,large-v3,turbo'],
            'language' => ['nullable', 'string', 'max:12'],
        ]);

        $user = $request->user();

        if (! empty($validated['transcription_folder_id'])) {
            TranscriptionFolder::whereBelongsTo($user)->findOrFail($validated['transcription_folder_id']);
        }

        foreach ($validated['files'] as $uploadedFile) {
            $extension = $uploadedFile->getClientOriginalExtension() ?: $uploadedFile->extension();
            $storedPath = $uploadedFile->storeAs(
                'audios/'.$user->id,
                Str::uuid().($extension ? '.'.$extension : ''),
                'local',
            );

            $transcriptionFile = TranscriptionFile::create([
                'user_id' => $user->id,
                'transcription_folder_id' => $validated['transcription_folder_id'] ?? null,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => $uploadedFile->getMimeType(),
                'size' => $uploadedFile->getSize() ?: 0,
                'language' => $validated['language'] ?: null,
                'model' => $validated['model'],
                'status' => 'queued',
            ]);

            ProcessTranscriptionFile::dispatch($transcriptionFile);
        }

        return back()->with('status', 'Archivos enviados a transcripcion.');
    }

    public function storeFromPaths(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:50'],
            'paths.*' => ['required', 'string'],
            'transcription_folder_id' => ['nullable', 'integer', 'exists:transcription_folders,id'],
            'model' => ['required', 'string', 'in:tiny,base,small,medium,large,large-v2,large-v3,turbo'],
            'language' => ['nullable', 'string', 'max:12'],
            'clean_audio' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        if (! $user->canUploadMore()) {
            return back()->withErrors([
                'paths' => "Alcanzaste el límite de {$user->audio_limit} audios. Borrá alguno para subir uno nuevo.",
            ]);
        }

        $remainingSlots = $user->hasUnlimitedAudio()
            ? PHP_INT_MAX
            : max(0, (int) $user->audio_limit - $user->audioUsage());

        if (count($validated['paths']) > $remainingSlots) {
            return back()->withErrors([
                'paths' => "Solo te quedan {$remainingSlots} cupos disponibles. Reducí la selección o borrá audios viejos.",
            ]);
        }

        if (! empty($validated['transcription_folder_id'])) {
            TranscriptionFolder::whereBelongsTo($user)->findOrFail($validated['transcription_folder_id']);
        }

        $allowed = ['mp3', 'wav', 'm4a', 'mp4', 'webm', 'ogg', 'oga', 'flac', 'aac'];
        $mimeTypes = new MimeTypes();

        foreach ($validated['paths'] as $rawPath) {
            $real = realpath($rawPath);

            if ($real === false || ! is_file($real)) {
                return back()->withErrors(['paths' => "Archivo no encontrado: {$rawPath}"]);
            }

            $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            if (! in_array($extension, $allowed, true)) {
                return back()->withErrors(['paths' => "Extensión no soportada: {$rawPath}"]);
            }

            $mime = $mimeTypes->guessMimeType($real) ?: 'application/octet-stream';

            $transcriptionFile = TranscriptionFile::create([
                'user_id' => $user->id,
                'transcription_folder_id' => $validated['transcription_folder_id'] ?? null,
                'original_name' => basename($real),
                'stored_path' => $real,
                'mime_type' => $mime,
                'size' => @filesize($real) ?: 0,
                'language' => $validated['language'] ?: null,
                'clean_audio' => (bool) ($validated['clean_audio'] ?? false),
                'model' => $validated['model'],
                'status' => 'queued',
            ]);

            ProcessTranscriptionFile::dispatch($transcriptionFile);
        }

        return back()->with('status', 'Archivos enviados a transcripción.');
    }

    public function destroy(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        if ($transcriptionFile->worker_pid) {
            $this->killWorkerProcess((int) $transcriptionFile->worker_pid);
        }

        $storedPath = (string) $transcriptionFile->stored_path;
        $isAbsolute = preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $storedPath) === 1;

        if (! $isAbsolute && $storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }

        Storage::disk('local')->delete('transcripts/'.$transcriptionFile->id.'.json');

        if ($transcriptionFile->cleaned_audio_path) {
            Storage::disk('local')->delete($transcriptionFile->cleaned_audio_path);
        }

        $transcriptionFile->delete();

        return back()->with('status', 'Transcripción eliminada.');
    }

    private function killWorkerProcess(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $kill = new \Symfony\Component\Process\Process(['taskkill', '/F', '/T', '/PID', (string) $pid]);
                $kill->run();
            } elseif (function_exists('posix_kill')) {
                @posix_kill($pid, defined('SIGKILL') ? SIGKILL : 9);
            }
            \Illuminate\Support\Facades\Log::info('Whisper worker killed on delete', ['pid' => $pid]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to kill whisper worker', [
                'pid' => $pid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function download(Request $request, TranscriptionFile $transcriptionFile, string $format): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);
        abort_unless(in_array($format, ['txt', 'srt', 'pdf'], true), 404);

        $transcriptionFile->load('transcription.segments');
        $transcription = $transcriptionFile->transcription;
        abort_unless($transcription, 404, 'La transcripción aún no está lista.');

        $baseName = pathinfo($transcriptionFile->original_name, PATHINFO_FILENAME);
        $safeName = Str::slug($baseName) ?: 'transcripcion-'.$transcriptionFile->id;

        if ($format === 'txt') {
            $content = trim($transcription->effectiveText());
            return response($content, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$safeName.'.txt"',
            ]);
        }

        if ($format === 'srt') {
            $segments = $transcription->effectiveSegments();
            $lines = [];
            foreach ($segments as $i => $segment) {
                $lines[] = (string) ($i + 1);
                $lines[] = $this->formatSrtTime((float) $segment['start']).' --> '.$this->formatSrtTime((float) $segment['end']);
                $lines[] = trim((string) $segment['text']);
                $lines[] = '';
            }
            $content = implode("\n", $lines);
            return response($content, 200, [
                'Content-Type' => 'application/x-subrip; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$safeName.'.srt"',
            ]);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.transcription-pdf', [
            'file' => $transcriptionFile,
            'transcription' => $transcription,
            'segments' => $transcription->segments,
        ]);

        return $pdf->download($safeName.'.pdf');
    }

    private function formatSrtTime(float $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = (int) floor($seconds) % 60;
        $millis = (int) round(($seconds - floor($seconds)) * 1000);
        if ($millis >= 1000) {
            $secs += 1;
            $millis = 0;
        }
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    public function streamAudio(Request $request, TranscriptionFile $transcriptionFile): BinaryFileResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $path = $transcriptionFile->absolutePath();

        abort_unless(is_file($path) && is_readable($path), 404);

        return response()->file($path, [
            'Content-Type' => $transcriptionFile->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function streamArtwork(Request $request, TranscriptionFile $transcriptionFile): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $path = $transcriptionFile->absolutePath();
        abort_unless(is_file($path) && is_readable($path), 404);

        $picture = app(\App\Services\Audio\AudioMetadataReader::class)->readPicture($path);
        abort_unless($picture !== null, 404, 'El audio no tiene carátula embebida.');

        return response($picture['data'], 200, [
            'Content-Type' => $picture['mime'],
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function streamCleanedAudio(Request $request, TranscriptionFile $transcriptionFile): BinaryFileResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);
        abort_unless($transcriptionFile->cleaned_audio_path, 404);

        $path = Storage::disk('local')->path($transcriptionFile->cleaned_audio_path);
        abort_unless(is_file($path) && is_readable($path), 404);

        return response()->file($path, ['Content-Type' => 'audio/mpeg']);
    }

    public function replaceWithCleaned(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);
        abort_unless($transcriptionFile->cleaned_audio_path, 404);

        $cleanedAbsolute = Storage::disk('local')->path($transcriptionFile->cleaned_audio_path);
        abort_unless(is_file($cleanedAbsolute), 404, 'Audio limpio no encontrado en disco.');

        $originalPath = $transcriptionFile->absolutePath();
        $originalDir = dirname($originalPath);
        $originalExt = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'mp3');
        $originalBase = pathinfo($originalPath, PATHINFO_FILENAME);

        if ($request->user()->getSetting('backup_on_replace')) {
            $backupPath = $originalDir.DIRECTORY_SEPARATOR.$originalBase.'_original.'.$originalExt;
            $backupPath = $this->uniquePath($backupPath);
            if (! @copy($originalPath, $backupPath)) {
                return back()->with('error', 'No se pudo crear el backup. Reemplazo abortado.');
            }
        }

        if (! $this->encodeAudioWithFfmpeg($cleanedAbsolute, $originalPath, $originalExt)) {
            return back()->with('error', 'No se pudo reemplazar el audio original.');
        }

        @unlink($cleanedAbsolute);
        $transcriptionFile->update([
            'cleaned_audio_path' => null,
            'size' => @filesize($originalPath) ?: $transcriptionFile->size,
        ]);

        return back()->with('status', 'Audio original reemplazado con la versión limpia.');
    }

    public function saveCleanedAsNew(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);
        abort_unless($transcriptionFile->cleaned_audio_path, 404);

        $cleanedAbsolute = Storage::disk('local')->path($transcriptionFile->cleaned_audio_path);
        abort_unless(is_file($cleanedAbsolute), 404, 'Audio limpio no encontrado en disco.');

        $originalPath = $transcriptionFile->absolutePath();
        $originalDir = dirname($originalPath);
        $originalExt = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'mp3');
        $originalBase = pathinfo($originalPath, PATHINFO_FILENAME);

        $newPath = $originalDir.DIRECTORY_SEPARATOR.$originalBase.'_NR.'.$originalExt;
        $newPath = $this->uniquePath($newPath);

        if (! $this->encodeAudioWithFfmpeg($cleanedAbsolute, $newPath, $originalExt)) {
            return back()->with('error', 'No se pudo guardar la copia limpia.');
        }

        @unlink($cleanedAbsolute);
        $transcriptionFile->update(['cleaned_audio_path' => null]);

        return back()->with('status', 'Copia "_NR" guardada en la misma carpeta del original.');
    }

    public function discardCleaned(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        if ($transcriptionFile->cleaned_audio_path) {
            Storage::disk('local')->delete($transcriptionFile->cleaned_audio_path);
            $transcriptionFile->update(['cleaned_audio_path' => null]);
        }

        return back()->with('status', 'Audio limpio descartado.');
    }

    private function uniquePath(string $path): string
    {
        if (! file_exists($path)) {
            return $path;
        }
        $dir = dirname($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);
        $i = 2;
        while (true) {
            $candidate = $dir.DIRECTORY_SEPARATOR.$base.'_'.$i.($ext ? '.'.$ext : '');
            if (! file_exists($candidate)) {
                return $candidate;
            }
            $i++;
        }
    }

    private function encodeAudioWithFfmpeg(string $input, string $output, string $extension): bool
    {
        $codecArgs = match ($extension) {
            'mp3' => ['-codec:a', 'libmp3lame', '-b:a', '192k'],
            'm4a', 'aac', 'mp4' => ['-codec:a', 'aac', '-b:a', '192k'],
            'ogg', 'oga' => ['-codec:a', 'libvorbis', '-q:a', '5'],
            'flac' => ['-codec:a', 'flac'],
            'wav' => ['-codec:a', 'pcm_s16le'],
            'webm' => ['-codec:a', 'libopus', '-b:a', '128k'],
            default => ['-codec:a', 'libmp3lame', '-b:a', '192k'],
        };

        $process = new \Symfony\Component\Process\Process([
            'ffmpeg', '-y', '-loglevel', 'error', '-i', $input,
            ...$codecArgs,
            $output,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            \Illuminate\Support\Facades\Log::error('ffmpeg encode failed', [
                'input' => $input,
                'output' => $output,
                'extension' => $extension,
                'stderr' => $process->getErrorOutput(),
            ]);
            return false;
        }

        return true;
    }

    public function listUnfiled(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        $files = TranscriptionFile::query()
            ->whereBelongsTo($user)
            ->whereNull('transcription_folder_id')
            ->latest()
            ->get(['id', 'original_name', 'duration_seconds', 'created_at', 'status'])
            ->map(fn (TranscriptionFile $f) => [
                'id' => $f->id,
                'original_name' => $f->original_name,
                'duration_seconds' => $f->duration_seconds,
                'status' => $f->status,
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        return response()->json(['files' => $files]);
    }

    public function moveBulkToFolder(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'transcription_folder_id' => ['required', 'integer', 'exists:transcription_folders,id'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        TranscriptionFolder::whereBelongsTo($user)->findOrFail($validated['transcription_folder_id']);

        $updated = TranscriptionFile::query()
            ->whereBelongsTo($user)
            ->whereIn('id', $validated['ids'])
            ->update(['transcription_folder_id' => $validated['transcription_folder_id']]);

        return back()->with('status', $updated.' archivo(s) movidos a la carpeta.');
    }

    public function updateFolder(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'transcription_folder_id' => ['nullable', 'integer', 'exists:transcription_folders,id'],
        ]);

        if (! empty($validated['transcription_folder_id'])) {
            TranscriptionFolder::whereBelongsTo($request->user())
                ->findOrFail($validated['transcription_folder_id']);
        }

        $transcriptionFile->update([
            'transcription_folder_id' => $validated['transcription_folder_id'] ?? null,
        ]);

        return back()->with('status', 'Archivo movido.');
    }

    public function rename(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
        ]);

        $newName = trim($validated['original_name']);
        if ($newName === '') {
            return back()->withErrors(['original_name' => 'El nombre no puede estar vacío.']);
        }

        $transcriptionFile->update(['original_name' => $newName]);

        return back()->with('status', 'Nombre actualizado.');
    }

    public function show(Request $request, TranscriptionFile $transcriptionFile): Response
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $transcriptionFile->load(['folder', 'transcription.segments']);

        $user = $request->user();
        $usage = \App\Models\GroqUsage::todayFor($user->id);
        $limits = config('services.groq.free_tier');
        $summaryProvider = $user->getSetting('summary_provider') ?? 'groq';

        return Inertia::render('Transcriptions/Show', [
            // Los segmentos vienen en el HTML inicial para que el transcript
            // pueda renderizarse con párrafos, highlight de playback y click-to-seek.
            // El panel "Segmentos" del fondo (lista tipo SRT con todos los timestamps)
            // sí se trae a demanda vía /transcriptions/{id}/segments.
            'file' => $this->serializeFile($transcriptionFile, includeSegments: true, includeAudioMetadata: true),
            'summaryProvider' => $summaryProvider,
            'ollamaAvailable' => $this->isOllamaAvailable(),
            'groqUsage' => [
                'requests_count' => (int) $usage->requests_count,
                'tokens_used' => (int) $usage->tokens_used,
                'limits' => [
                    'requests_per_day' => (int) $limits['requests_per_day'],
                    'tokens_per_day' => (int) $limits['tokens_per_day'],
                ],
                'configured' => (bool) config('services.groq.key'),
            ],
            'ollamaConfig' => [
                'model' => config('services.ollama.summary_model'),
                'base_url' => config('services.ollama.base_url'),
            ],
        ]);
    }

    /**
     * Pinguea Ollama (rápido + cacheado 60s) para saber si el worker está vivo.
     * Si está caído usamos esa info en la UI para hablar de Groq en vez de Ollama.
     */
    private function isOllamaAvailable(): bool
    {
        return \Illuminate\Support\Facades\Cache::remember('ollama_available', 60, function (): bool {
            $base = rtrim((string) config('services.ollama.base_url', 'http://localhost:11434'), '/');
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(2)->get($base.'/api/tags');
                return $response->successful();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function summarize(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $transcription = $transcriptionFile->transcription;
        if (! $transcription || empty($transcription->text)) {
            return back()->withErrors(['summary' => 'La transcripción aún no tiene texto disponible.']);
        }

        $provider = $request->user()->getSetting('summary_provider') ?? 'groq';
        if ($provider === 'groq' && ! config('services.groq.key')) {
            return back()->withErrors(['summary' => 'Falta configurar GROQ_APIKEY en el servidor.']);
        }

        $transcription->update([
            'summary_status' => 'queued',
            'summary_metadata' => array_merge($transcription->summary_metadata ?? [], ['error' => null]),
        ]);

        \App\Jobs\SummarizeTranscription::dispatch($transcription);

        return back()->with('status', 'Generando resumen…');
    }

    public function updateText(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:1000000'],  // 1MB de texto, generoso
        ]);

        $transcriptionFile->load('transcription.segments');
        $transcription = $transcriptionFile->transcription;
        if (! $transcription) {
            return back()->withErrors(['text' => 'La transcripción aún no está lista.']);
        }

        $newText = trim($validated['text']);
        $isSameAsOriginal = $newText === trim((string) $transcription->text);

        if ($isSameAsOriginal) {
            $transcription->update([
                'edited_text' => null,
                'edited_at' => null,
                'effective_segments' => null,
            ]);
            return back()->with('success', 'No hay cambios respecto al original.');
        }

        // Reconciliar los segmentos via word-level diff para mantener karaoke + SRT en sync.
        $reconciler = app(\App\Services\Transcription\SegmentReconciler::class);
        $originalSegments = $transcription->segments->map(fn ($s) => [
            'start' => (float) $s->start_seconds,
            'end' => (float) $s->end_seconds,
            'text' => (string) $s->text,
            'position' => (int) $s->position,
        ])->all();

        $effective = $reconciler->reconcile($originalSegments, $newText);

        $transcription->update([
            'edited_text' => $newText,
            'edited_at' => now(),
            'effective_segments' => $effective,
        ]);

        return back()->with('success', 'Transcripción guardada (segmentos resincronizados).');
    }

    public function restoreText(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $transcription = $transcriptionFile->transcription;
        if (! $transcription) {
            return back();
        }

        $transcription->update([
            'edited_text' => null,
            'edited_at' => null,
            'effective_segments' => null,
        ]);

        return back()->with('success', 'Restaurado al texto original de Whisper.');
    }

    public function cancelSummary(Request $request, TranscriptionFile $transcriptionFile): RedirectResponse
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $transcription = $transcriptionFile->transcription;
        if (! $transcription) {
            return back();
        }

        // 1. Marcamos la transcripción para que el job (si está corriendo) se entere
        //    al chequear summary_status y aborte temprano.
        $transcription->update([
            'summary_status' => 'failed',
            'summary_metadata' => array_merge($transcription->summary_metadata ?? [], [
                'error' => 'Cancelado manualmente.',
                'cancelled_at' => now()->toIso8601String(),
            ]),
        ]);

        // 2. Best-effort: borrar de la cola los jobs que apuntan a esta transcripción.
        //    El payload de Laravel guarda la ID dentro del JSON serializado; lo buscamos
        //    por substring que no choca con otros jobs.
        try {
            \DB::table('jobs')
                ->where('payload', 'like', '%SummarizeTranscription%')
                ->where('payload', 'like', '%"id";i:'.$transcription->id.';%')
                ->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Cancel summary: queue cleanup failed', [
                'transcription_id' => $transcription->id,
                'message' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Resumen cancelado.');
    }

    private function serializeFile(TranscriptionFile $file, bool $includeSegments = false, bool $includeAudioMetadata = false): array
    {
        // Sólo leemos ID3 cuando estamos en la vista detalle — es relativamente costoso.
        $audioMetadata = null;
        if ($includeAudioMetadata) {
            $absolute = $file->absolutePath();
            if (is_file($absolute) && is_readable($absolute)) {
                try {
                    $audioMetadata = app(\App\Services\Audio\AudioMetadataReader::class)->read($absolute);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to read audio metadata', [
                        'file_id' => $file->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'duration_seconds' => $file->duration_seconds,
            'language' => $file->language,
            'model' => $file->model,
            'status' => $file->status,
            'progress' => (int) $file->progress,
            'has_cleaned_audio' => (bool) $file->cleaned_audio_path,
            'error_message' => $file->error_message,
            'processed_at' => $file->processed_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
            'audio_metadata' => $audioMetadata,
            'folder' => $file->folder ? [
                'id' => $file->folder->id,
                'name' => $file->folder->name,
                'parent' => $file->folder->parent ? [
                    'id' => $file->folder->parent->id,
                    'name' => $file->folder->parent->name,
                ] : null,
            ] : null,
            'transcription' => $file->transcription ? [
                'id' => $file->transcription->id,
                'text' => $file->transcription->effectiveText(),
                'original_text' => $file->transcription->text,
                'edited' => $file->transcription->isEdited(),
                'edited_at' => $file->transcription->edited_at?->toIso8601String(),
                'metadata' => $file->transcription->metadata,
                'summary' => $file->transcription->summary,
                'key_points' => is_array($file->transcription->summary_metadata ?? null)
                    ? ($file->transcription->summary_metadata['key_points'] ?? [])
                    : [],
                'summary_status' => $file->transcription->summary_status ?? 'idle',
                'summary_error' => is_array($file->transcription->summary_metadata ?? null)
                    ? ($file->transcription->summary_metadata['error'] ?? null)
                    : null,
                'summary_model' => is_array($file->transcription->summary_metadata ?? null)
                    ? ($file->transcription->summary_metadata['model'] ?? null)
                    : null,
                'summary_elapsed_seconds' => is_array($file->transcription->summary_metadata ?? null)
                    ? ($file->transcription->summary_metadata['elapsed_seconds'] ?? null)
                    : null,
                'summary_generated_at' => $file->transcription->summary_generated_at?->toIso8601String(),
                'segments_count' => count($file->transcription->effectiveSegments() ?? []),
                'segments' => $includeSegments
                    ? collect($file->transcription->effectiveSegments())->map(fn ($segment, $i) => [
                        'id' => 'eff-'.$i,
                        'position' => $segment['position'] ?? $i,
                        'start_seconds' => $segment['start'],
                        'end_seconds' => $segment['end'],
                        'text' => $segment['text'],
                    ])
                    : [],
            ] : null,
        ];
    }

}
