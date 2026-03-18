<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MemorySummary model - stores hierarchical conversation summaries.
 *
 * Part of the lossless memory compaction system:
 * - Leaf summaries (depth 0): Summarize raw conversation messages
 * - Condensed summaries (depth 1+): Summarize lower-level summaries
 *
 * @property string $summary_id Primary key (e.g., "sum_abc123...")
 * @property int $conversation_id FK to conversations.id
 * @property string $kind 'leaf' or 'condensed'
 * @property int $depth Summary depth (0 = leaf, 1+ = condensed)
 * @property string $content Summary text content
 * @property int $token_count Approximate token count
 * @property \Carbon\Carbon|null $earliest_at Earliest timestamp of source content
 * @property \Carbon\Carbon|null $latest_at Latest timestamp of source content
 * @property int $descendant_count Number of descendant summaries
 * @property int $descendant_token_count Total tokens in descendants
 * @property int $source_message_token_count Original message tokens before compaction
 * @property array $file_ids File IDs referenced in this summary
 */
class MemorySummary extends Model
{
    protected $table = 'memory_summaries';

    protected $primaryKey = 'summary_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'summary_id',
        'conversation_id',
        'kind',
        'depth',
        'content',
        'token_count',
        'earliest_at',
        'latest_at',
        'descendant_count',
        'descendant_token_count',
        'source_message_token_count',
        'file_ids',
    ];

    protected $casts = [
        'depth' => 'integer',
        'token_count' => 'integer',
        'earliest_at' => 'datetime',
        'latest_at' => 'datetime',
        'descendant_count' => 'integer',
        'descendant_token_count' => 'integer',
        'source_message_token_count' => 'integer',
        'file_ids' => 'array',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * The conversation this summary belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Messages that this leaf summary summarizes.
     * Only applies to leaf summaries (depth 0).
     */
    public function sourceMessages(): BelongsToMany
    {
        return $this->belongsToMany(
            ConversationMessage::class,
            'memory_summary_messages',
            'summary_id',
            'message_id'
        )->withPivot('ordinal')->orderByPivot('ordinal');
    }

    /**
     * Parent summaries that this condensed summary summarizes.
     * Only applies to condensed summaries (depth 1+).
     */
    public function parentSummaries(): BelongsToMany
    {
        return $this->belongsToMany(
            MemorySummary::class,
            'memory_summary_parents',
            'summary_id',
            'parent_summary_id'
        )->withPivot('ordinal')->orderByPivot('ordinal');
    }

    /**
     * Child summaries that were created from this summary.
     * This summary is a parent of these children.
     */
    public function childSummaries(): BelongsToMany
    {
        return $this->belongsToMany(
            MemorySummary::class,
            'memory_summary_parents',
            'parent_summary_id',
            'summary_id'
        )->withPivot('ordinal')->orderByPivot('ordinal');
    }

    /**
     * Context items that reference this summary.
     */
    public function contextItems(): HasMany
    {
        return $this->hasMany(MemoryContextItem::class, 'summary_id', 'summary_id');
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope for leaf summaries (depth 0).
     */
    public function scopeLeaf($query)
    {
        return $query->where('kind', 'leaf');
    }

    /**
     * Scope for condensed summaries (depth 1+).
     */
    public function scopeCondensed($query)
    {
        return $query->where('kind', 'condensed');
    }

    /**
     * Scope for specific depth.
     */
    public function scopeAtDepth($query, int $depth)
    {
        return $query->where('depth', $depth);
    }

    /**
     * Scope for summaries in a conversation.
     */
    public function scopeForConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Check if this is a leaf summary.
     */
    public function isLeaf(): bool
    {
        return $this->kind === 'leaf';
    }

    /**
     * Check if this is a condensed summary.
     */
    public function isCondensed(): bool
    {
        return $this->kind === 'condensed';
    }

    /**
     * Get the compression ratio (source tokens / summary tokens).
     */
    public function getCompressionRatio(): float
    {
        if ($this->token_count === 0) {
            return 0.0;
        }

        return $this->source_message_token_count / $this->token_count;
    }

    /**
     * Generate a deterministic summary ID.
     */
    public static function generateId(string $content): string
    {
        return 'sum_'.substr(hash('sha256', $content.microtime(true)), 0, 16);
    }
}
