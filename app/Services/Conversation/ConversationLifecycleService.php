<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\EpisodicEventDTO;
use App\Enums\ChannelEnum;
use App\Enums\EpisodicEventTypeEnum;
use App\Logging\MultiLogger;
use App\Models\Conversation;
use App\Services\MemoryEngineService;
use Illuminate\Support\Str;

/**
 * ConversationLifecycleService - Single source of truth for conversation creation and message storage.
 *
 * This service encapsulates the full lifecycle of a conversation:
 * - Finding or creating conversations
 * - Adding user messages and agent responses
 * - Updating conversation metadata (title, last message timestamp)
 * - Recording episodic memory events
 *
 * Both CommandProcessingService (WebSocket) and ProcessMessageJob (queue) delegate
 * to this service, eliminating duplicated conversation management logic.
 */
class ConversationLifecycleService
{
    protected ?MemoryEngineService $memoryService = null;

    /**
     * Set the memory service for episodic memory recording.
     */
    public function setMemoryService(MemoryEngineService $memoryService): self
    {
        $this->memoryService = $memoryService;

        return $this;
    }

    /**
     * Find an existing conversation or create a new one.
     *
     * @param  string|null  $conversationId  Existing conversation ID to look up
     * @param  ChannelEnum  $channel  The channel type
     * @param  string  $sender  The sender display name
     * @param  string|null  $senderId  The unique sender identifier
     * @param  string|null  $titleHint  Optional hint for the conversation title (typically first message)
     */
    public function findOrCreate(
        ?string $conversationId,
        ChannelEnum $channel,
        string $sender = 'user',
        ?string $senderId = null,
        ?string $titleHint = null
    ): Conversation {
        // Try to find existing conversation
        if ($conversationId) {
            $conversation = Conversation::where('conversation_id', $conversationId)->first();
            if ($conversation) {
                // Ensure sender_id is set (for conversations created before this fix)
                if (empty($conversation->sender_id) && $senderId) {
                    $conversation->update(['sender_id' => $senderId]);
                }

                return $conversation;
            }
        }

        // Generate IDs
        $newConversationId = $conversationId ?? Str::uuid()->toString();
        $newSenderId = $senderId ?? $this->generateSenderId($channel, $newConversationId);

        return Conversation::create([
            'conversation_id' => $newConversationId,
            'channel' => $channel,
            'sender' => $sender,
            'sender_id' => $newSenderId,
            'is_active' => true,
            'derived_title' => $titleHint
                ? mb_substr($titleHint, 0, 50).(strlen($titleHint) > 50 ? '...' : '')
                : null,
        ]);
    }

    /**
     * Record a full exchange (user message + agent response) in a conversation.
     *
     * @param  Conversation  $conversation  The conversation to record in
     * @param  string  $userMessage  The user's message
     * @param  string  $agentId  The responding agent's ID
     * @param  string  $agentName  The responding agent's display name
     * @param  string  $agentResponse  The agent's response text
     * @param  string|null  $provider  The LLM provider
     * @param  string|null  $model  The LLM model
     */
    public function recordExchange(
        Conversation $conversation,
        string $userMessage,
        string $agentId,
        string $agentName,
        string $agentResponse,
        ?string $provider = null,
        ?string $model = null
    ): void {
        // Add user message
        $conversation->addUserMessage(
            $userMessage,
            'user',
            $conversation->sender_id,
            []
        );

        // Add agent response
        $conversation->addAgentResponse(
            $agentId,
            $agentName,
            $agentResponse,
            $provider,
            $model
        );

        // Update conversation metadata
        $conversation->updateDerivedTitle();
        $conversation->touchLastMessage();
    }

    /**
     * Record an episodic memory event for the exchange.
     *
     * This is a fire-and-forget operation — failures are logged but not propagated.
     */
    public function recordMemory(
        string $senderId,
        ChannelEnum $channel,
        string $userMessage,
        string $agentResponse
    ): void {
        if (! $this->memoryService || ! $this->memoryService->isEnabled()) {
            return;
        }

        $content = $this->memoryService->truncateText('User: '.$userMessage);
        $outcome = $this->memoryService->truncateText($agentResponse);

        // If truncation returns null, memory is disabled
        if ($content === null || $outcome === null) {
            return;
        }

        try {
            $this->memoryService->recordEvent(
                $senderId,
                $channel,
                new EpisodicEventDTO(
                    type: EpisodicEventTypeEnum::TASK_COMPLETED,
                    content: $content,
                    outcome: $outcome,
                )
            );
        } catch (\Exception $e) {
            MultiLogger::warning("Failed to record episodic event: {$e->getMessage()}");
        }
    }

    /**
     * Generate a sender ID from channel and conversation context.
     */
    protected function generateSenderId(ChannelEnum $channel, string $conversationId): string
    {
        $prefix = match ($channel) {
            ChannelEnum::DISCORD => 'discord',
            ChannelEnum::TELEGRAM => 'telegram',
            ChannelEnum::WHATSAPP => 'whatsapp',
            ChannelEnum::CLI => 'cli',
            ChannelEnum::WEBSOCKET => 'ws',
        };

        return "{$prefix}_".substr($conversationId, 0, 8);
    }
}
