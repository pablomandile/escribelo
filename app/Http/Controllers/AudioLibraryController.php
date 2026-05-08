<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioLibraryController extends Controller
{
    private const AUDIO_EXTENSIONS = [
        'mp3', 'wav', 'm4a', 'mp4', 'webm', 'ogg', 'oga', 'flac', 'aac',
    ];

    public function browse(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['nullable', 'string'],
        ]);

        $path = $request->input('path');

        if ($path === null || $path === '') {
            return response()->json([
                'path' => null,
                'parent' => null,
                'directories' => $this->listDrives(),
                'files' => [],
            ]);
        }

        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            return response()->json(['message' => 'Carpeta inexistente.'], 404);
        }

        if (! is_readable($real)) {
            return response()->json(['message' => 'Carpeta sin permisos de lectura.'], 403);
        }

        $directories = [];
        $files = [];

        $entries = @scandir($real);
        if ($entries === false) {
            return response()->json(['message' => 'No se pudo leer la carpeta.'], 500);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $real.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($full)) {
                $directories[] = [
                    'name' => $entry,
                    'path' => $full,
                ];
                continue;
            }

            if (! is_file($full)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                continue;
            }

            $files[] = [
                'name' => $entry,
                'path' => $full,
                'size' => @filesize($full) ?: 0,
            ];
        }

        usort($directories, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return response()->json([
            'path' => $real,
            'parent' => dirname($real) === $real ? null : dirname($real),
            'directories' => $directories,
            'files' => $files,
        ]);
    }

    private function listDrives(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [['name' => '/', 'path' => '/']];
        }

        $drives = [];
        for ($code = ord('A'); $code <= ord('Z'); $code++) {
            $letter = chr($code);
            $path = $letter.':\\';
            if (is_dir($path)) {
                $drives[] = ['name' => $letter.':', 'path' => $path];
            }
        }

        return $drives;
    }
}
