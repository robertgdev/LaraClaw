<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Conversation;

/**
 * Represents the result of handling a session intent.
 *
 * Used by SessionService::handleSessionIntent() to return
 * the outcome of session-related commands.
 */
final readonly class SessionIntentResultDTO
{
    public function __construct(
        public bool $handled,
        public ?string $response = null,
        public ?Conversation $session = null,
    ) {}

    /**
     * Create a handled result with response.
     */
    public static function handled(string $response, ?Conversation $session = null): self
    {
        return new self(handled: true, response: $response, session: $session);
    }

    /**
     * Create a not-handled result.
     */
    public static function notHandled(): self
    {
        return new self(handled: false, response: null, session: null);
    }
}
