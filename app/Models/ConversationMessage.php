<?php

namespace App\Models;

use App\Enums\ChannelEnum;
use App\Enums\FeedbackEnum;
use App\Enums\MessageStatusEnum;
use App\Enums\QueueTypeEnum;
use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * ConversationMessage model - stores individual messages within conversations.
 *
 * This model replaces the old Message model with a normalized structure:
 * - Each message belongs to a conversation (via conversation_id)
 * - Messages have a direction: 'incoming' (from user) or 'outgoing' (from agent)
 * - All message content is stored here, not in the conversations table
 * - Supports user feedback (positive, negative, neutral)
 */
class ConversationMessage extends Model
{
    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'message_id',
        'conversation_id',
        'channel',
        'direction',
        'is_internal',
        'sender',
        'sender_id',
        'message',
        'agent_id',
        'provider',
        'model',
        'is_llm',
        'files',
        'status',
        'queue_type',
        'retry_count',
        'error_message',
        'processed_at',
        'reply_to',
        'feedback',
        'feedback_comment',
        'feedback_at',
    ];

    protected $casts = [
        'files' => 'array',
        'is_internal' => 'boolean',
        'status' => MessageStatusEnum::class,
        'queue_type' => QueueTypeEnum::class,
        'channel' => ChannelEnum::class,
        'processed_at' => 'datetime',
        'feedback' => FeedbackEnum::class,
        'feedback_at' => 'datetime',
    ];

    protected $attributes = [
        'is_internal' => false,
    ];

    // Direction constants
    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the agent this message belongs to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'agent_id');
    }

    /**
     * Get the conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }

    /**
     * Get the message this is a reply to.
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'reply_to', 'id');
    }

    /**
     * Get replies to this message.
     *
     * @return HasMany<ConversationMessage, ConversationMessage>
     */
    public function replies(): HasMany
    {
        /** @var HasMany<ConversationMessage, ConversationMessage> */
        return $this->hasMany(ConversationMessage::class, 'reply_to', 'id');
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (ConversationMessage $message) {
            if (empty($message->message_id)) {
                $message->message_id = (string) Str::uuid();
            }
        });
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope for incoming messages (from users).
     */
    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_INCOMING);
    }

    /**
     * Scope for outgoing messages (from agents).
     */
    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUTGOING);
    }

    /**
     * Scope for pending messages.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', MessageStatusEnum::PENDING);
    }

    /**
     * Scope for processing messages.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', MessageStatusEnum::PROCESSING);
    }

    /**
     * Scope for completed messages.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', MessageStatusEnum::COMPLETED);
    }

    /**
     * Scope for failed messages.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', MessageStatusEnum::FAILED);
    }

    /**
     * Scope for incoming queue type.
     */
    public function scopeQueueIncoming(Builder $query): Builder
    {
        return $query->where('queue_type', QueueTypeEnum::INCOMING);
    }

    /**
     * Scope for outgoing queue type.
     */
    public function scopeQueueOutgoing(Builder $query): Builder
    {
        return $query->where('queue_type', QueueTypeEnum::OUTGOING);
    }

    /**
     * Scope for processing queue type.
     */
    public function scopeQueueProcessing(Builder $query): Builder
    {
        return $query->where('queue_type', QueueTypeEnum::PROCESSING);
    }

    /**
     * Scope for specific channel.
     */
    public function scopeForChannel(Builder $query, ChannelEnum $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope for messages with positive feedback.
     */
    public function scopePositiveFeedback(Builder $query): Builder
    {
        return $query->where('feedback', FeedbackEnum::POSITIVE);
    }

    /**
     * Scope for messages with negative feedback.
     */
    public function scopeNegativeFeedback(Builder $query): Builder
    {
        return $query->where('feedback', FeedbackEnum::NEGATIVE);
    }

    /**
     * Scope for messages with any feedback.
     */
    public function scopeWithFeedback(Builder $query): Builder
    {
        return $query->whereNotNull('feedback');
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Check if this is an incoming message.
     */
    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_INCOMING;
    }

    /**
     * Check if this is an outgoing message.
     */
    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUTGOING;
    }

    /**
     * Mark message as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => MessageStatusEnum::PROCESSING,
            'queue_type' => QueueTypeEnum::PROCESSING,
        ]);
    }

    /**
     * Mark message as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => MessageStatusEnum::COMPLETED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark message as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => MessageStatusEnum::FAILED,
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    // ==========================================
    // Feedback Methods
    // ==========================================

    /**
     * Set feedback for this message.
     *
     * Uses withoutTimestamps() so that recording feedback does not alter updated_at.
     */
    public function setFeedback(FeedbackEnum $feedback, ?string $comment = null): void
    {
        static::withoutTimestamps(function () use ($feedback, $comment) {
            $this->update([
                'feedback' => $feedback,
                'feedback_comment' => $comment,
                'feedback_at' => now(),
            ]);
        });
    }

    /**
     * Check if this message has feedback.
     */
    public function hasFeedback(): bool
    {
        return $this->feedback !== null;
    }

    /**
     * Check if this message has positive feedback.
     */
    public function hasPositiveFeedback(): bool
    {
        return $this->feedback?->isPositive() ?? false;
    }

    /**
     * Check if this message has negative feedback.
     */
    public function hasNegativeFeedback(): bool
    {
        return $this->feedback?->isNegative() ?? false;
    }

    // ==========================================
    // Factory Methods
    // ==========================================

    /**
     * Create an incoming message (from user).
     *
     * @param array{
     *     conversation_id: string,
     *     channel: ChannelEnum,
     *     sender: string,
     *     sender_id: string|null,
     *     message: string,
     *     fullMessage?: string,
     *     agent_id?: string
     * } $data
     */
    public static function createIncoming(array $data): self
    {
        return static::create(array_merge($data, [
            'direction' => self::DIRECTION_INCOMING,
            'status' => $data['status'] ?? MessageStatusEnum::PENDING,
            'queue_type' => $data['queue_type'] ?? QueueTypeEnum::INCOMING,
        ]));
    }

    /**
     * Create an outgoing message (from agent).
     *
     * @param array{
     *      conversation_id: string,
     *      channel: ChannelEnum,
     *      sender: string,
     *      sender_id: string|null,
     *      message: string,
     *      agent_id: string|null,
     *      provider?: string|null,
     *      model?: string|null,
     *      files?: array<int, string>|null,
     *      is_llm?: bool,
     *      reply_to?: int|null,
     *      status?: MessageStatusEnum,
     *      processed_at?: string|null
     *  } $data
     */
    public static function createOutgoing(array $data): self
    {
        return static::create(array_merge($data, [
            'direction' => self::DIRECTION_OUTGOING,
            'status' => $data['status'] ?? MessageStatusEnum::PENDING,
            'queue_type' => $data['queue_type'] ?? QueueTypeEnum::OUTGOING,
        ]));
    }

    // ==========================================
    // Data Conversion Methods
    // ==========================================

    /**
     * Convert to array format
     *
     * @return array{
     *       channel: string,
     *       sender: string,
     *       senderId: string|null,
     *       message: string,
     *       timestamp: int,
     *       messageId: string,
     *       agent: string|null,
     *       files: array<int, string>|null,
     *       conversationId: string,
     *       direction: string,
     *       isInternal: bool,
     *       feedback: int|null,
     *       feedbackComment: string|null,
     *   }
     */
    public function toMessageData(): array
    {
        return [
            'channel' => $this->channel->value,
            'sender' => $this->sender,
            'senderId' => $this->sender_id,
            'message' => $this->message,
            'timestamp' => $this->created_at->timestamp * 1000,
            'messageId' => $this->message_id,
            'agent' => $this->agent_id,
            'files' => $this->files,
            'conversationId' => $this->conversation_id,
            'direction' => $this->direction,
            'isInternal' => $this->is_internal,
            'feedback' => $this->feedback?->value,
            'feedbackComment' => $this->feedback_comment,
        ];
    }

    /**
     * Convert to response data format.
     *
     * @return array{
     *        channel: string,
     *        sender: string,
     *        senderId: string|null,
     *        message: string,
     *        timestamp: int,
     *        messageId: string,
     *        agent: string|null,
     *        files: array<int, string>|null,
     *        provider: string|null,
     *        model: string|null,
     *        feedback: int|null,
     *        feedbackComment: string|null,
     *    }
     */
    public function toResponseData(): array
    {
        return [
            'channel' => $this->channel->value,
            'sender' => $this->sender,
            'senderId' => $this->sender_id,
            'message' => $this->message,
            'timestamp' => $this->created_at->timestamp * 1000,
            'messageId' => $this->message_id,
            'agent' => $this->agent_id,
            'files' => $this->files,
            'provider' => $this->provider,
            'model' => $this->model,
            'feedback' => $this->feedback?->value,
            'feedbackComment' => $this->feedback_comment,
        ];
    }
}
