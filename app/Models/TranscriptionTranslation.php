<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscriptionTranslation extends Model
{
    /** Idiomas a los que se puede traducir, con su nombre para la UI. */
    public const LANGUAGES = [
        'es' => 'Español',
        'en' => 'Inglés',
        'pt' => 'Portugués',
    ];

    protected $fillable = [
        'transcription_id',
        'language',
        'text',
        'status',
        'metadata',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }
}
