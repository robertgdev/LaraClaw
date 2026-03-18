<?php

namespace App\Models;

use App\Helpers\TokenEstimatorHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MemoryContextItem model - ordered list of messages and summaries forming conversation context.
 *
 * Each context item represents either:
 * - A raw conversation message (item_type = 'message')
 * - A summary of previous messages (item_type = 'summary')
 *
 * The ordinal field maintains the order of items in the context window.
 * During compaction, ranges of messages are replaced with summaries.
 *
 * @property int $conversation_id FK to conversations.id
 * @property int $ordinal Position in context (0-based, contiguous)
 * @property string $item_type 'message' or 'summary'
 * @property int|null $message_id FK to conversation_messages.id (if message type)
 * @property string|null $summary_id FK to memory_summaries.summary_id (if summary type)
 * @property \Carbon\Carbon $created_at
 */
class MemoryContextItem extends Model
{
    protected $table = 'memory_context_items';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'ordinal',
        'item_type',
        'message_id',
        'summary_id',
        'created_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'ordinal' => 'integer',
        'message_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected $primaryKey = null;

    public $incrementing = false;

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * The conversation this context item belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The message this context item references (if message type).
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id', 'id');
    }

    /**
     * The summary this context item references (if summary type).
     */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(MemorySummary::class, 'summary_id', 'summary_id');
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope for items in a conversation.
     */
    public function scopeForConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    /**
     * Scope for message items.
     */
    public function scopeMessages($query)
    {
        return $query->where('item_type', 'message');
    }

    /**
     * Scope for summary items.
     */
    public function scopeSummaries($query)
    {
        return $query->where('item_type', 'summary');
    }

    /**
     * Scope ordered by ordinal.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('ordinal');
    }

    /**
     * Scope for items before a given ordinal.
     */
    public function scopeBeforeOrdinal($query, int $ordinal)
    {
        return $query->where('ordinal', '<', $ordinal);
    }

    /**
     * Scope for items at or after a given ordinal.
     */
    public function scopeFromOrdinal($query, int $ordinal)
    {
        return $query->where('ordinal', '>=', $ordinal);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Check if this is a message item.
     */
    public function isMessage(): bool
    {
        return $this->item_type === 'message';
    }

    /**
     * Check if this is a summary item.
     */
    public function isSummary(): bool
    {
        return $this->item_type === 'summary';
    }

    /**
     * Get the token count for this context item.
     */
    public function getTokenCount(): int
    {
        if ($this->isMessage() && $this->message) {
            return $this->estimateTokens($this->message->message ?? '');
        }

        if ($this->isSummary() && $this->summary) {
            return $this->summary->token_count;
        }

        return 0;
    }

    /**
     * Estimate token count from content.
     */
    private function estimateTokens(string $content): int
    {
        return TokenEstimatorHelper::estimate($content);
    }
}
