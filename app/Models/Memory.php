<?php

namespace App\Models;

use App\DTOs\MemoryStatsDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * Memory model - stores timestamped events with outcomes and importance scoring.
 *
 * Part of the 3-layer adaptive memory system:
 * - Layer 1: Episodic Memory — timestamped events with outcomes & importance scoring
 * - Layer 2: Semantic Index — FTS5/full-text search with BM25 ranking
 * - Layer 3: Temporal Decay — Ebbinghaus forgetting curve + access frequency strengthening
 * - Layer 4: Feedback Score — user feedback influences memory relevance
 *
 * @property int $id Auto-incrementing integer primary key
 * @property int|null $conversation_id FK to conversations.id
 * @property float $search_score Runtime search score (not persisted)
 * @property float|null $feedback_score Feedback score from -1.0 to 1.0
 * @property int $feedback_count Number of feedback signals received
 */
class Memory extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'channel',
        'agent_id',
        'event_type',
        'content',
        'outcome',
        'importance',
        'feedback_score',
        'feedback_count',
        'access_count',
        'last_accessed_at',
        'created_at',
    ];

    protected $casts = [
        'channel' => ChannelEnum::class,
        'event_type' => EpisodicEventTypeEnum::class,
        'importance' => 'decimal:2',
        'feedback_score' => 'decimal:2',
        'feedback_count' => 'integer',
        'access_count' => 'integer',
        'created_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * The conversation this memory belongs to (if any).
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

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
            'channel' => $this->channel->value ?? $this->channel,
            'content' => $this->content,
            'outcome' => $this->outcome,
            'event_type' => $this->event_type->value ?? $this->event_type,
        ];
    }

    /**
     * Determine if the model should be searchable.
     * Soft-deleted memories are never searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->importance >= 0.1 && ! $this->trashed();
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope for specific sender and channel.
     */
    public function scopeForSender(Builder $query, string $senderId, ChannelEnum $channel): Builder
    {
        return $query->where('sender_id', $senderId)
            ->where('channel', $channel);
    }

    /**
     * Scope for high importance memories.
     */
    public function scopeHighImportance(Builder $query, float $threshold = 0.7): Builder
    {
        return $query->where('importance', '>=', $threshold);
    }

    /**
     * Scope for recent memories.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope for specific event type.
     */
    public function scopeForEventType(Builder $query, EpisodicEventTypeEnum $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope for memories not accessed in N days.
     */
    public function scopeNotAccessedFor(Builder $query, int $days): Builder
    {
        return $query->where('last_accessed_at', '<', now()->subDays($days));
    }

    /**
     * Scope for low importance memories.
     */
    public function scopeLowImportance(Builder $query, float $threshold = 0.1): Builder
    {
        return $query->where('importance', '<', $threshold);
    }

    /**
     * Scope for unaccessed memories.
     */
    public function scopeUnaccessed(Builder $query): Builder
    {
        return $query->where('access_count', 0);
    }

    /**
     * Scope for memories with positive feedback.
     */
    public function scopePositiveFeedback(Builder $query, float $threshold = 0.5): Builder
    {
        return $query->where('feedback_score', '>=', $threshold);
    }

    /**
     * Scope for memories with negative feedback.
     */
    public function scopeNegativeFeedback(Builder $query, float $threshold = -0.5): Builder
    {
        return $query->where('feedback_score', '<=', $threshold);
    }

    /**
     * Scope for memories with any feedback.
     */
    public function scopeWithFeedback(Builder $query): Builder
    {
        return $query->whereNotNull('feedback_score');
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

    /**
     * Apply feedback to this memory.
     * Updates the feedback score using a running average.
     *
     * @param  float  $feedbackValue  Feedback value (-1.0 to 1.0)
     */
    public function applyFeedback(float $feedbackValue): void
    {
        $currentScore = $this->feedback_score ?? 0.0;
        $currentCount = $this->feedback_count ?? 0;

        // Calculate new average
        $newCount = $currentCount + 1;
        $newScore = (($currentScore * $currentCount) + $feedbackValue) / $newCount;

        // Clamp to valid range
        $newScore = max(-1.0, min(1.0, $newScore));

        $this->update([
            'feedback_score' => $newScore,
            'feedback_count' => $newCount,
        ]);
    }

    /**
     * Check if this memory has positive feedback.
     */
    public function hasPositiveFeedback(): bool
    {
        return ($this->feedback_score ?? 0) > 0.3;
    }

    /**
     * Check if this memory has negative feedback.
     */
    public function hasNegativeFeedback(): bool
    {
        return ($this->feedback_score ?? 0) < -0.3;
    }

    public static function statsForSender(string $senderId, ChannelEnum $channel): MemoryStatsDTO
    {
        return static::forSender($senderId, $channel)
            ->selectRaw('
                COUNT(*) as total,
                AVG(importance) as avgImportance,
                SUM(CASE WHEN last_accessed_at < ? THEN 1 ELSE 0 END) as oldCount,
                SUM(CASE WHEN importance < 0.1 AND access_count = 0 THEN 1 ELSE 0 END) as pruneCandidates
            ', [now()->subDays(7)])
            ->get()
            ->mapInto(MemoryStatsDTO::class)
            ->first();
    }
}
