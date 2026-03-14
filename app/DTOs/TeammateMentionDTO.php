<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a teammate mention extracted from a response.
 *
 * Used when an agent mentions another teammate in their response
 * using the [@agent_id: message] format.
 */
final readonly class TeammateMentionDTO
{
    public function __construct(
        public string $teammateId,
        public string $message,
    ) {}
}
