<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transcription extends Model
{
    use HasFactory;

    protected $fillable = [
        'transcription_file_id',
        'text',
        'edited_text',
        'edited_at',
        'effective_segments',
        'metadata',
        'summary',
        'summary_metadata',
        'summary_status',
        'summary_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'summary_metadata' => 'array',
            'effective_segments' => 'array',
            'summary_generated_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Devuelve el texto vigente: la versión editada por el usuario si existe,
     * o el original (output de Whisper) en caso contrario. Usado en summary y exports.
     */
    public function effectiveText(): string
    {
        $edited = trim((string) $this->edited_text);
        return $edited !== '' ? $edited : (string) $this->text;
    }

    public function isEdited(): bool
    {
        return $this->edited_text !== null && trim((string) $this->edited_text) !== '';
    }

    /**
     * Devuelve los segmentos vigentes con timestamps:
     *  - Si no hay edición: los segmentos originales del DB.
     *  - Si hay edición: la versión reconciliada via word-level diff (cacheada en effective_segments).
     *
     * Resultado uniforme: array de [start, end, text, position] con position virtual.
     */
    public function effectiveSegments(): array
    {
        if ($this->isEdited() && is_array($this->effective_segments) && ! empty($this->effective_segments)) {
            return collect($this->effective_segments)->values()->map(fn ($seg, $i) => [
                'start' => (float) ($seg['start'] ?? 0),
                'end' => (float) ($seg['end'] ?? 0),
                'text' => (string) ($seg['text'] ?? ''),
                'position' => $i,
            ])->all();
        }

        return $this->segments->map(fn ($s) => [
            'start' => (float) $s->start_seconds,
            'end' => (float) $s->end_seconds,
            'text' => (string) $s->text,
            'position' => (int) $s->position,
        ])->all();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(TranscriptionFile::class, 'transcription_file_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptionSegment::class)->orderBy('position');
    }
}
