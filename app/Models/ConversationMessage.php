<?php

namespace App\Models;

use App\Enums\ChannelEnum;
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
    ];

    protected $casts = [
        'files' => 'array',
        'is_internal' => 'boolean',
        'status' => MessageStatusEnum::class,
        'queue_type' => QueueTypeEnum::class,
        'channel' => ChannelEnum::class,
        'processed_at' => 'datetime',
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
     */
    public function replies(): HasMany
    {
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
    // Factory Methods
    // ==========================================

    /**
     * Create an incoming message (from user).
     *
     * @param array{
     *     conversation_id: string,
     *     channel: ChannelEnum,
     *     sender: string,
     *     sender_id: string,
     *     message: string,
     *     fullMessage: string
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
     *      sender_id: string,
     *      message: string,
     *      agent_id: string,
     *      provider: string,
     *      model: string,
     *      status: MessageStatusEnum,
     *      processed_at: string
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
     *       channel: ChannelEnum,
     *       sender: string,
     *       sender_id: string,
     *       message: string,
     *       timestamp: string,
     *       message_id: string,
     *       agent: string,
     *       files: array<string>,
     *       conversation_id: string,
     *       direction: string,
     *       isInternal: bool,
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
        ];
    }

    /**
     * Convert to response data format.
     *
     * @return array{
     *        channel: ChannelEnum,
     *        sender: string,
     *        sender_id: string,
     *        message: string,
     *        timestamp: string,
     *        message_id: string,
     *        agent: string,
     *        files: array<string>,
     *        provider: string,
     *        model: string,
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
        ];
    }
}
