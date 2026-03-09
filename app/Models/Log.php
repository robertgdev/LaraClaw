<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Log extends Model
{
    use SoftDeletes;

    protected $table = 'logs';

    protected $fillable = [
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    /**
     * Scope for filtering by log level.
     */
    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', strtoupper($level));
    }

    /**
     * Scope for errors and above.
     */
    public function scopeErrors($query)
    {
        return $query->whereIn('level', ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']);
    }

    /**
     * Scope for recent logs.
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }
}
