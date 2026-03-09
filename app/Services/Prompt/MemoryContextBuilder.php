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
     * @return string|null The memory context section, or null if no relevant memories
     */
    public function build(string $senderId, ChannelEnum $channel, ?string $message = null): ?string
    {
        $memoryContext = $this->memoryService->getContextForAgent(
            $senderId,
            $channel,
            $message
        );

        return ! empty($memoryContext) ? $memoryContext : null;
    }
}
