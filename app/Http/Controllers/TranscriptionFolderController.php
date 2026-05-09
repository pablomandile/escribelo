<?php

namespace App\Http\Controllers;

use App\Models\TranscriptionFile;
use App\Models\TranscriptionFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TranscriptionFolderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $unfiledCount = TranscriptionFile::query()
            ->whereBelongsTo($user)
            ->whereNull('transcription_folder_id')
            ->count();

        $folders = TranscriptionFolder::query()
            ->whereBelongsTo($user)
            ->whereNull('parent_id')
            ->withCount(['files', 'children'])
            ->orderBy('name')
            ->get()
            ->map(fn (TranscriptionFolder $folder) => [
                'id' => $folder->id,
                'name' => $folder->name,
                'files_count' => $folder->files_count,
                'children_count' => $folder->children_count,
            ]);

        return Inertia::render('Library/Index', [
            'folders' => $folders,
            'unfiledCount' => $unfiledCount,
        ]);
    }

    public function show(Request $request, TranscriptionFolder $folder): Response
    {
        abort_unless($folder->user_id === $request->user()->id, 403);

        $folder->load([
            'parent:id,name,parent_id',
            'children' => fn ($query) => $query->withCount('files')->orderBy('name'),
            'files' => fn ($query) => $query->latest(),
        ]);

        return Inertia::render('Library/Show', [
            'folder' => [
                'id' => $folder->id,
                'name' => $folder->name,
                'parent' => $folder->parent ? [
                    'id' => $folder->parent->id,
                    'name' => $folder->parent->name,
                ] : null,
                'children' => $folder->children->map(fn (TranscriptionFolder $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'files_count' => $child->files_count,
                ])->all(),
                'files' => $folder->files->map(fn (TranscriptionFile $file) => [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'duration_seconds' => $file->duration_seconds,
                    'model' => $file->model,
                    'status' => $file->status,
                    'created_at' => $file->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:transcription_folders,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('transcription_folders', 'name')
                    ->where('user_id', $user->id)
                    ->where('parent_id', $request->input('parent_id')),
            ],
        ]);

        if (! empty($validated['parent_id'])) {
            $parent = TranscriptionFolder::whereBelongsTo($user)->findOrFail($validated['parent_id']);

            if ($parent->parent_id !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Solo se permiten dos niveles de carpetas.',
                ]);
            }
        }

        TranscriptionFolder::create([
            'user_id' => $user->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
        ]);

        return back()->with('status', 'Carpeta creada.');
    }

    public function destroy(Request $request, TranscriptionFolder $folder): RedirectResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 403);

        $folder->delete();

        return redirect()
            ->route('dashboard')
            ->with('success', 'Carpeta eliminada. Las transcripciones quedaron en "Sin ordenar".');
    }
}
