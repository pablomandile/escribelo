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
            'summary_generated_at' => 'datetime',
        ];
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
