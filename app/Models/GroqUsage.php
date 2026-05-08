<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroqUsage extends Model
{
    protected $table = 'groq_usage';

    protected $fillable = [
        'user_id',
        'date',
        'requests_count',
        'tokens_used',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public static function todayFor(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId, 'date' => today()],
            ['requests_count' => 0, 'tokens_used' => 0],
        );
    }

    public static function recordCall(int $userId, int $tokens): void
    {
        $row = static::todayFor($userId);
        $row->increment('requests_count');
        if ($tokens > 0) {
            $row->increment('tokens_used', $tokens);
        }
    }
}
