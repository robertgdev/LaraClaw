<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a single entry in session history.
 *
 * Used by SessionService::getSessionHistory() to return chat history
 * in a format suitable for LLM context.
 */
final readonly class SessionHistoryEntryDTO
{
    public function __construct(
        public string $role,
        public string $content,
    ) {}

    /**
     * Check if this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
