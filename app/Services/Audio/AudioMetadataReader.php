<?php

namespace App\Services\Audio;

use getID3;
use Illuminate\Support\Facades\Cache;

class AudioMetadataReader
{
    /**
     * Lee metadata (ID3, MP4, etc) de un archivo de audio. Cachea el resultado por path+mtime
     * para no re-parsear en cada request.
     *
     * @return array{
     *     artist: ?string, album: ?string, composer: ?string,
     *     year: ?string, genre: ?string,
     *     has_picture: bool, picture_mime: ?string,
     * }
     */
    public function read(string $absolutePath): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return $this->emptyResult();
        }

        $mtime = @filemtime($absolutePath) ?: 0;
        $key = 'audio_meta:'.md5($absolutePath).':'.$mtime;

        return Cache::remember($key, 86400, function () use ($absolutePath) {
            return $this->parse($absolutePath);
        });
    }

    /**
     * Devuelve los bytes crudos de la imagen embebida (carátula) o null si no hay.
     * No cacheamos esto en memoria — se sirve por endpoint streaming.
     *
     * @return array{mime: string, data: string}|null
     */
    public function readPicture(string $absolutePath): ?array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        try {
            $id3 = new getID3();
            $info = $id3->analyze($absolutePath);

            $picture = $info['comments']['picture'][0] ?? null;
            if (! is_array($picture) || empty($picture['data'])) {
                return null;
            }

            return [
                'mime' => (string) ($picture['image_mime'] ?? 'image/jpeg'),
                'data' => (string) $picture['data'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parse(string $absolutePath): array
    {
        try {
            $id3 = new getID3();
            $info = $id3->analyze($absolutePath);

            $tags = $info['tags'] ?? [];
            // Preferencia: id3v2 > id3v1 > quicktime > otros
            $primary = $tags['id3v2'] ?? $tags['id3v1'] ?? $tags['quicktime'] ?? $tags['vorbiscomment'] ?? [];

            $picture = $info['comments']['picture'][0] ?? null;

            return [
                'artist' => $this->firstNonEmpty($primary, 'artist'),
                'album' => $this->firstNonEmpty($primary, 'album'),
                'composer' => $this->firstNonEmpty($primary, 'composer'),
                'year' => $this->firstNonEmpty($primary, 'year'),
                'genre' => $this->firstNonEmpty($primary, 'genre'),
                'has_picture' => is_array($picture) && ! empty($picture['data']),
                'picture_mime' => is_array($picture) ? ($picture['image_mime'] ?? null) : null,
            ];
        } catch (\Throwable $e) {
            return $this->emptyResult();
        }
    }

    private function firstNonEmpty(array $tags, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $tags[$key] ?? null;
            if (is_array($value)) {
                $value = reset($value);
            }
            $value = is_string($value) ? trim($value) : null;
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function emptyResult(): array
    {
        return [
            'artist' => null,
            'album' => null,
            'composer' => null,
            'year' => null,
            'genre' => null,
            'has_picture' => false,
            'picture_mime' => null,
        ];
    }
}
