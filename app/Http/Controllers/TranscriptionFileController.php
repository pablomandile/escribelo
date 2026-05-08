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

        $files = TranscriptionFile::query()
            ->with(['folder.parent', 'transcription'])
            ->whereBelongsTo($user)
            ->latest()
            ->limit(50)
            ->get()
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
        ]);

        $user = $request->user();

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

        $storedPath = (string) $transcriptionFile->stored_path;
        $isAbsolute = preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $storedPath) === 1;

        if (! $isAbsolute && $storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }

        Storage::disk('local')->delete('transcripts/'.$transcriptionFile->id.'.json');

        $transcriptionFile->delete();

        return back()->with('status', 'Transcripción eliminada.');
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

    public function show(Request $request, TranscriptionFile $transcriptionFile): Response
    {
        abort_unless($transcriptionFile->user_id === $request->user()->id, 403);

        $transcriptionFile->load(['folder', 'transcription.segments']);

        return Inertia::render('Transcriptions/Show', [
            'file' => $this->serializeFile($transcriptionFile, includeSegments: true),
        ]);
    }

    private function serializeFile(TranscriptionFile $file, bool $includeSegments = false): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'duration_seconds' => $file->duration_seconds,
            'language' => $file->language,
            'model' => $file->model,
            'status' => $file->status,
            'error_message' => $file->error_message,
            'processed_at' => $file->processed_at?->toIso8601String(),
            'created_at' => $file->created_at?->toIso8601String(),
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
                'text' => $file->transcription->text,
                'metadata' => $file->transcription->metadata,
                'segments' => $includeSegments
                    ? $file->transcription->segments->map(fn ($segment) => [
                        'id' => $segment->id,
                        'position' => $segment->position,
                        'start_seconds' => $segment->start_seconds,
                        'end_seconds' => $segment->end_seconds,
                        'text' => $segment->text,
                    ])
                    : [],
            ] : null,
        ];
    }
}
