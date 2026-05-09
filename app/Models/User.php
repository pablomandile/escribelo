<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'settings', 'role', 'approval_status', 'approved_at', 'audio_limit'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const DEFAULT_SETTINGS = [
        'backup_on_replace' => true,
        'theme' => 'light',
        'notify_on_complete' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'audio_limit' => 'integer',
        ];
    }

    public function getSetting(string $key)
    {
        $settings = $this->settings ?? [];

        return $settings[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function transcriptionFiles(): HasMany
    {
        return $this->hasMany(TranscriptionFile::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function hasUnlimitedAudio(): bool
    {
        return $this->audio_limit === null;
    }

    public function audioUsage(): int
    {
        return $this->transcriptionFiles()->count();
    }

    public function canUploadMore(): bool
    {
        return $this->hasUnlimitedAudio() || $this->audioUsage() < (int) $this->audio_limit;
    }
}
