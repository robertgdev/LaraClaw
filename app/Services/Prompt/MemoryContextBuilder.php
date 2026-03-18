<?php

declare(strict_types=1);

namespace App\Services\Prompt;

use App\Enums\ChannelEnum;
use App\Services\MemoryEngineService;

/**
 * Builds memory context sections for the system prompt.
 *
 * Retrieves relevant episodic and key-value memories for the current
 * sender/channel context and formats them for injection into the prompt.
 * Also includes lossless memory context when available.
 */
class MemoryContextBuilder
{
    public function __construct(
        protected MemoryEngineService $memoryService
    ) {}

    /**
     * Build memory context for injection into the system prompt.
     *
     * @param  string  $senderId  The sender's identifier
     * @param  ChannelEnum  $channel  The communication channel
     * @param  string|null  $message  The current user message (for relevance search)
     * @param  int|null  $conversationId  The conversation ID for lossless context
     * @return string|null The memory context section, or null if no relevant memories
     */
    public function build(string $senderId, ChannelEnum $channel, ?string $message = null, ?int $conversationId = null): ?string
    {
        $sections = [];

        // Get lossless memory context if enabled and conversation ID provided
        if ($conversationId && $this->memoryService->isLosslessEnabled()) {
            $losslessContext = $this->buildLosslessContext($conversationId);
            if (! empty($losslessContext)) {
                $sections[] = "\n## Conversation History (Summarized)\n".$losslessContext;
            }
        }

        return ! empty($sections) ? implode("\n", $sections) : null;
    }

    /**
     * Build lossless context for a conversation.
     *
     * @param  int  $conversationId  The conversation ID
     * @param  int  $maxTokens  Maximum tokens to include
     * @return string|null The lossless context, or null if not available
     */
    public function buildLosslessContext(int $conversationId, int $maxTokens = 4000): ?string
    {
        if (! $this->memoryService->isLosslessEnabled()) {
            return null;
        }

        try {
            return $this->memoryService->getLosslessContextForAgent($conversationId, $maxTokens);
        } catch (\Exception $e) {
            \App\Logging\MultiLogger::warning("Failed to build lossless context: {$e->getMessage()}");

            return null;
        }
    }
}
