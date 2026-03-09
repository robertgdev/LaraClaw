<?php

namespace App\Services;

use App\DTOs\ConversationStateDTO;
use App\Logging\MultiLogger;
use App\TypedCollections\ConversationStateDTOCollection;
use Illuminate\Support\Facades\Cache;

/**
 * ConversationStateManager - Manages conversation state using Laravel Cache.
 *
 * This service replaces the static in-memory conversation tracking with
 * a cache-backed implementation that supports Redis, database, or file-based
 * caching through Laravel's unified cache API.
 */
class ConversationStateManagerService
{
    protected string $cachePrefix;

    protected int $ttl;

    protected int $maxMessages;

    /**
     * Create a new ConversationStateManager instance.
     */
    public function __construct()
    {
        $this->cachePrefix = config('laraclaw.conversation.cache_prefix', 'laraclaw:conv:');
        $this->ttl = config('laraclaw.conversation.cache_ttl', 3600); // 1 hour default
        $this->maxMessages = config('laraclaw.conversation.max_messages', 50);
    }

    /**
     * Get the cache key for a conversation.
     */
    protected function getCacheKey(string $conversationId): string
    {
        return $this->cachePrefix.$conversationId;
    }

    /**
     * Create a new conversation state.
     *
     * @param  ConversationStateDTO  $conversation  The conversation DTO to store
     * @return ConversationStateDTO The stored conversation with its generated ID
     */
    public function create(ConversationStateDTO $conversation): ConversationStateDTO
    {
        $this->store($conversation);

        $teamName = $conversation->teamContext['team']['name'] ?? 'unknown';
        MultiLogger::info("Conversation created: {$conversation->id} (team: {$teamName})");

        return $conversation;
    }

    /**
     * Store a conversation state in cache.
     */
    public function store(ConversationStateDTO $conversation): bool
    {
        $key = $this->getCacheKey($conversation->id);

        return Cache::put($key, $conversation->toArray(), $this->ttl);
    }

    /**
     * Get a conversation state by ID.
     */
    public function get(string $conversationId): ?ConversationStateDTO
    {
        $key = $this->getCacheKey($conversationId);
        $data = Cache::get($key);

        if ($data === null) {
            return null;
        }

        return ConversationStateDTO::fromArray($data);
    }

    /**
     * Check if a conversation exists.
     */
    public function exists(string $conversationId): bool
    {
        return Cache::has($this->getCacheKey($conversationId));
    }

    /**
     * Update a conversation state.
     */
    public function update(ConversationStateDTO $conversation): bool
    {
        return $this->store($conversation);
    }

    /**
     * Delete a conversation state.
     */
    public function delete(string $conversationId): bool
    {
        $key = $this->getCacheKey($conversationId);

        MultiLogger::info("Conversation deleted: {$conversationId}");

        return Cache::forget($key);
    }

    /**
     * Get or create a conversation state.
     *
     * @param  string  $conversationId  The conversation ID to look up
     * @param  ConversationStateDTO  $conversation  The conversation DTO to use if not found
     * @return ConversationStateDTO The existing or newly created conversation
     */
    public function getOrCreate(string $conversationId, ConversationStateDTO $conversation): ConversationStateDTO
    {
        $existing = $this->get($conversationId);

        if ($existing !== null) {
            return $existing;
        }

        // Override the ID with the requested one
        $conversation->id = $conversationId;

        return $this->create($conversation);
    }

    /**
     * Add a response to a conversation.
     */
    public function addResponse(string $conversationId, string $agentId, string $response, ?string $agentName = null): ?ConversationStateDTO
    {
        $conversation = $this->get($conversationId);

        if ($conversation === null) {
            MultiLogger::warning("Attempted to add response to non-existent conversation: {$conversationId}");

            return null;
        }

        $conversation->addResponse($agentId, $response, $agentName);
        $this->update($conversation);

        return $conversation;
    }

    /**
     * Add files to a conversation.
     *
     * @param  array<string>  $files
     */
    public function addFiles(string $conversationId, array $files): ?ConversationStateDTO
    {
        $conversation = $this->get($conversationId);

        if ($conversation === null) {
            return null;
        }

        $conversation->addFiles($files);
        $this->update($conversation);

        return $conversation;
    }

    /**
     * Increment the pending counter for a conversation.
     */
    public function incrementPending(string $conversationId, int $count = 1): ?ConversationStateDTO
    {
        $conversation = $this->get($conversationId);

        if ($conversation === null) {
            return null;
        }

        $conversation->incrementPending($count);
        $this->update($conversation);

        return $conversation;
    }

    /**
     * Decrement the pending counter for a conversation.
     */
    public function decrementPending(string $conversationId): ?ConversationStateDTO
    {
        $conversation = $this->get($conversationId);

        if ($conversation === null) {
            return null;
        }

        $conversation->decrementPending();
        $this->update($conversation);

        return $conversation;
    }

    /**
     * Check if a conversation is complete (no pending responses).
     */
    public function isComplete(string $conversationId): bool
    {
        $conversation = $this->get($conversationId);

        return $conversation !== null && $conversation->isComplete();
    }

    /**
     * Complete a conversation and remove it from cache.
     * Returns the final state before deletion.
     */
    public function complete(string $conversationId): ?ConversationStateDTO
    {
        $conversation = $this->get($conversationId);

        if ($conversation === null) {
            return null;
        }

        $summary = sprintf(
            'Conversation %s: %d responses, %d pending, %d total messages, %ds elapsed',
            $conversation->id,
            count($conversation->responses),
            $conversation->pending,
            $conversation->totalMessages,
            time() - $conversation->startTime
        );
        MultiLogger::info("Conversation completed: {$summary}");

        $this->delete($conversationId);

        return $conversation;
    }

    /**
     * Get all active conversation IDs.
     *
     * Note: This requires a cache store that supports pattern-based key retrieval.
     * For Redis, this uses KEYS command. For other stores, this may not work.
     *
     * @return array<string, string>
     */
    public function getActiveConversationIds(): array
    {
        $store = Cache::getStore();

        // Redis-specific implementation
        if (method_exists($store, 'connection')) {
            $pattern = $this->cachePrefix.'*';
            $keys = $store->connection()->keys($pattern);

            // Remove prefix from keys
            return array_map(function ($key) {
                return str_replace($this->cachePrefix, '', $key);
            }, $keys);
        }

        // For other cache stores, we maintain a separate index
        $indexKey = $this->cachePrefix.'index';

        return Cache::get($indexKey, []);
    }

    /**
     * Get all active conversations.
     */
    public function getActiveConversations(): ConversationStateDTOCollection
    {
        $ids = $this->getActiveConversationIds();
        $conversations = new ConversationStateDTOCollection;

        foreach ($ids as $id) {
            $conversation = $this->get($id);
            if ($conversation) {
                $conversations->put($id, $conversation);
            }
        }

        return $conversations;
    }

    /**
     * Get the count of active conversations.
     */
    public function getActiveCount(): int
    {
        return count($this->getActiveConversationIds());
    }

    /**
     * Clean up expired conversations.
     *
     * Note: Most cache stores handle TTL expiration automatically.
     * This method is for manual cleanup if needed.
     */
    public function cleanup(): int
    {
        $ids = $this->getActiveConversationIds();
        $cleaned = 0;

        foreach ($ids as $id) {
            if (! $this->exists($id)) {
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            MultiLogger::info("Cleaned up {$cleaned} expired conversation references");
        }

        return $cleaned;
    }

    /**
     * Set the TTL for new conversations.
     */
    public function setTtl(int $seconds): self
    {
        $this->ttl = $seconds;

        return $this;
    }

    /**
     * Get the current TTL setting.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }
}
