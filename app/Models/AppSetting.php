<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(self::cacheKey($key), function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Effective Whisper transcription timeout (in seconds). Admin-configurable
     * via the AppSetting 'whisper_timeout'; falls back to .env/config if unset.
     */
    public static function whisperTimeout(): int
    {
        $configured = static::get('whisper_timeout');
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }
        return (int) config('transcription.timeout', 14400);
    }

    /**
     * URL efectiva del worker remoto. Editable desde el panel admin
     * (AppSetting 'remote_worker_url'); si está vacía cae al .env/config.
     * Permite cambiar la URL del túnel sin tocar el .env ni recachear config.
     */
    public static function remoteWorkerUrl(): string
    {
        $configured = static::get('remote_worker_url');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return (string) config('services.remote_worker.base_url', '');
    }

    private static function cacheKey(string $key): string
    {
        return 'app_settings:'.$key;
    }
}
