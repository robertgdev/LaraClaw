<?php

namespace App\Models;

use App\Enums\ChannelEnum;
use App\Enums\MessageStatusEnum;
use App\Services\Conversation\ConversationSearchService;
use App\Services\Conversation\ConversationSessionManager;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * Conversation model - also serves as Session.
 *
 * Each conversation IS a session (WebClaw-style):
 * - conversation_id: UUID identifier (also serves as session_id)
 * - label: User-assigned name (via "rename session to X")
 * - derived_title: Auto-generated from first message
 * - is_active: Currently active session for this sender_id+channel
 * - is_pinned: Pinned sessions appear at top
 *
 * Session lifecycle management is handled by ConversationSessionManager.
 * Search logic is handled by ConversationSearchService.
 * Static methods are retained as backward-compatible proxies.
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'channel',
        'sender',
        'sender_id',
        'team_id',
        'label',
        'derived_title',
        'is_active',
        'is_pinned',
        'total_messages',
        'started_at',
        'last_message_at',
        'completed_at',
    ];

    protected $casts = [
        'channel' => ChannelEnum::class,
        'is_active' => 'boolean',
        'is_pinned' => 'boolean',
        'started_at' => 'datetime',
        'last_message_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the team this conversation belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'team_id');
    }

    /**
     * Get all messages for this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id', 'conversation_id');
    }

    /**
     * Get incoming messages for this conversation.
     */
    public function incomingMessages(): HasMany
    {
        return $this->messages()->where('direction', 'incoming');
    }

    /**
     * Get outgoing messages for this conversation.
     */
    public function outgoingMessages(): HasMany
    {
        return $this->messages()->where('direction', 'outgoing');
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Conversation $conversation) {
            if (empty($conversation->conversation_id)) {
                $conversation->conversation_id = (string) Str::uuid();
            }
        });
    }

    // ==========================================
    // Scopes
    // ==========================================

    public function scopeForChannel(Builder $query, ChannelEnum $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeForTeam(Builder $query, ?string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForSender(Builder $query, string $senderId, ChannelEnum $channel): Builder
    {
        return $query->where('sender_id', $senderId)->where('channel', $channel);
    }

    // ==========================================
    // Factory Methods
    // ==========================================

    /**
     * Create a new conversation with metadata.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createNew(array $data): self
    {
        return static::create([
            'channel' => $data['channel'],
            'sender' => $data['sender'],
            'sender_id' => $data['sender_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'label' => $data['label'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'total_messages' => 0,
            'started_at' => now(),
        ]);
    }

    // ==========================================
    // Display Helpers
    // ==========================================

    /**
     * Get the display title for this session.
     * Priority: label > derived_title > first message > conversation_id
     */
    public function getDisplayTitle(): string
    {
        if (! empty($this->label)) {
            return $this->label;
        }

        if (! empty($this->derived_title)) {
            return $this->derived_title;
        }

        $firstMessage = $this->getFirstUserMessage();
        if ($firstMessage) {
            return Str::limit($firstMessage->message, 50);
        }

        return $this->conversation_id;
    }

    /**
     * Update derived title from first message.
     */
    public function updateDerivedTitle(): void
    {
        $firstMessage = $this->getFirstUserMessage();
        if ($firstMessage && empty($this->label)) {
            $this->update([
                'derived_title' => Str::limit($firstMessage->message, 100),
            ]);
        }
    }

    // ==========================================
    // Session Proxies (delegate to ConversationSessionManager)
    // ==========================================

    /**
     * Start a new session for a sender.
     * Deactivates all other sessions for this sender+channel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function startNewSession(array $data): self
    {
        return app(ConversationSessionManager::class)->startNewSession($data);
    }

    /**
     * Get the active session for a sender+channel.
     */
    public static function getActiveSession(string $senderId, ChannelEnum $channel): ?self
    {
        return app(ConversationSessionManager::class)->getActiveSession($senderId, $channel);
    }

    /**
     * Get or create an active session for a sender+channel.
     */
    public static function getOrCreateActiveSession(string $senderId, ChannelEnum $channel, string $sender = 'user'): self
    {
        return app(ConversationSessionManager::class)->getOrCreateActiveSession($senderId, $channel, $sender);
    }

    /**
     * Get all sessions for a sender+channel.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function getSessionsForSender(string $senderId, ChannelEnum $channel, int $limit = 50)
    {
        return app(ConversationSessionManager::class)->getSessionsForSender($senderId, $channel, $limit);
    }

    // ==========================================
    // Session Instance Methods
    // ==========================================

    /**
     * Rename this session.
     */
    public function rename(string $label): void
    {
        $this->update(['label' => $label]);
    }

    /**
     * Toggle pin status.
     */
    public function togglePin(): bool
    {
        $this->update(['is_pinned' => ! $this->is_pinned]);

        return $this->is_pinned;
    }

    /**
     * Set this session as active (deactivates others).
     */
    public function activate(): void
    {
        app(ConversationSessionManager::class)->activate($this);
    }

    /**
     * Update last message timestamp.
     */
    public function touchLastMessage(): void
    {
        $this->update(['last_message_at' => now()]);
    }

    // ==========================================
    // Message Helpers
    // ==========================================

    /**
     * Mark conversation as completed.
     */
    public function markCompleted(): void
    {
        $this->update(['completed_at' => now()]);
    }

    /**
     * Increment message count.
     */
    public function incrementMessageCount(int $count = 1): void
    {
        $this->increment('total_messages', $count);
    }

    /**
     * Add a user message to this conversation.
     *
     * @param  array<int, string>  $files
     */
    public function addUserMessage(string $message, string $sender = 'user', ?string $senderId = null, array $files = []): ConversationMessage
    {
        \Log::debug('[Conversation::addUserMessage] Creating message', [
            'conversation_id' => $this->conversation_id,
            'channel' => $this->channel->value,
            'sender' => $sender,
            'sender_id' => $senderId,
        ]);

        $msg = ConversationMessage::createIncoming([
            'conversation_id' => $this->conversation_id,
            'channel' => $this->channel,
            'sender' => $sender,
            'sender_id' => $senderId,
            'message' => $message,
            'files' => $files,
        ]);

        \Log::debug('[Conversation::addUserMessage] Message created', [
            'message_id' => $msg->id,
            'sender_id' => $msg->sender_id,
        ]);

        $this->incrementMessageCount();

        return $msg;
    }

    /**
     * Add an agent response to this conversation.
     */
    public function addAgentResponse(string $agentId, string $agentName, string $message, ?string $provider = null, ?string $model = null): ConversationMessage
    {
        $msg = ConversationMessage::createOutgoing([
            'conversation_id' => $this->conversation_id,
            'channel' => $this->channel,
            'sender' => $agentName,
            'sender_id' => $agentId,
            'message' => $message,
            'agent_id' => $agentId,
            'provider' => $provider,
            'model' => $model,
            'status' => MessageStatusEnum::COMPLETED,
            'processed_at' => now(),
        ]);

        $this->incrementMessageCount();

        return $msg;
    }

    /**
     * Get the first user message (for display/search purposes).
     */
    public function getFirstUserMessage(): ?ConversationMessage
    {
        /** @var ConversationMessage|null */
        return $this->incomingMessages()->orderBy('created_at')->first();
    }

    /**
     * Get all agent responses.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ConversationMessage>
     */
    public function getAgentResponses(): \Illuminate\Database\Eloquent\Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ConversationMessage> */
        return $this->outgoingMessages()->orderBy('created_at')->get();
    }

    // ==========================================
    // Search (delegates to ConversationSearchService)
    // ==========================================

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $firstMessage = $this->getFirstUserMessage();

        return [
            'conversation_id' => $this->conversation_id,
            'channel' => $this->channel->value,
            'sender' => $this->sender,
            'sender_id' => $this->sender_id,
            'team_id' => $this->team_id,
            'first_message' => $firstMessage?->message,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Search conversations using Laravel Scout or fallback to LIKE search.
     * Delegates to ConversationSearchService.
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Maximum results
     * @param  string|null  $teamId  Optional team filter
     * @return \Illuminate\Database\Eloquent\Builder<self>|\Laravel\Scout\Builder<self>
     */
    public static function searchConversations(string $query, int $limit = 20, ?string $teamId = null)
    {
        return app(ConversationSearchService::class)->search($query, $limit, $teamId);
    }
}
