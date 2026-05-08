<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class TranscriptionFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transcription_folder_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size',
        'duration_seconds',
        'language',
        'model',
        'status',
        'progress',
        'worker_pid',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(TranscriptionFolder::class, 'transcription_folder_id');
    }

    public function transcription(): HasOne
    {
        return $this->hasOne(Transcription::class);
    }

    public function absolutePath(): string
    {
        $path = (string) $this->stored_path;

        if (preg_match('/^([A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return Storage::disk('local')->path($path);
    }
}
