<?php

namespace App\Models;

use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Memory model - stores timestamped events with outcomes and importance scoring.
 *
 * Part of the 3-layer adaptive memory system:
 * - Layer 1: Episodic Memory — timestamped events with outcomes & importance scoring
 * - Layer 2: Semantic Index — FTS5/full-text search with BM25 ranking
 * - Layer 3: Temporal Decay — Ebbinghaus forgetting curve + access frequency strengthening
 */
class Memory extends Model
{
    use Searchable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'sender_id',
        'channel',
        'agent_id',
        'event_type',
        'content',
        'outcome',
        'importance',
        'access_count',
        'last_accessed_at',
        'created_at',
    ];

    protected $casts = [
        'channel' => ChannelEnum::class,
        'event_type' => EpisodicEventTypeEnum::class,
        'importance' => 'decimal:2',
        'access_count' => 'integer',
        'created_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    // ==========================================
    // Scout Configuration
    // ==========================================

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'channel' => $this->channel?->value ?? $this->channel,
            'content' => $this->content,
            'outcome' => $this->outcome,
            'event_type' => $this->event_type?->value ?? $this->event_type,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        // Don't index low-importance memories
        return $this->importance >= 0.1;
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope for specific sender and channel.
     */
    public function scopeForSender($query, string $senderId, ChannelEnum $channel)
    {
        return $query->where('sender_id', $senderId)
            ->where('channel', $channel);
    }

    /**
     * Scope for high importance memories.
     */
    public function scopeHighImportance($query, float $threshold = 0.7)
    {
        return $query->where('importance', '>=', $threshold);
    }

    /**
     * Scope for recent memories.
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope for specific event type.
     */
    public function scopeForEventType($query, EpisodicEventTypeEnum $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope for memories not accessed in N days.
     */
    public function scopeNotAccessedFor($query, int $days)
    {
        return $query->where('last_accessed_at', '<', now()->subDays($days));
    }

    /**
     * Scope for low importance memories.
     */
    public function scopeLowImportance($query, float $threshold = 0.1)
    {
        return $query->where('importance', '<', $threshold);
    }

    /**
     * Scope for unaccessed memories.
     */
    public function scopeUnaccessed($query)
    {
        return $query->where('access_count', 0);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Increment access count and update last accessed timestamp.
     */
    public function reinforce(): void
    {
        $this->increment('access_count', 1, [
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Decay importance by a factor.
     */
    public function decayImportance(float $factor = 0.95): void
    {
        $newImportance = $this->importance * $factor;

        // Don't decay below minimum threshold
        if ($newImportance >= 0.05) {
            $this->update(['importance' => $newImportance]);
        }
    }
}
