<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'type',
        'data',
        'occurred_at',
    ];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Emit an event (like the Node.js emitEvent function).
     */
    public static function emit(string $type, array $data): self
    {
        return static::create([
            'type' => $type,
            'data' => $data,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Scope for specific event type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for recent events.
     */
    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('occurred_at', '>=', now()->subMinutes($minutes));
    }
}
