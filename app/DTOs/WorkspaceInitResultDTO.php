<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of workspace initialization.
 *
 * Used by SetupWorkspaceInitializer::createDirectories() to return
 * messages about what was created.
 */
final readonly class WorkspaceInitResultDTO
{
    /**
     * @param array<string> $messages Messages describing what was created
     */
    public function __construct(
        public array $messages = [],
    ) {}

    /**
     * Check if any operations were performed.
     */
    public function hasMessages(): bool
    {
        return ! empty($this->messages);
    }

    /**
     * Get the number of operations performed.
     */
    public function count(): int
    {
        return count($this->messages);
    }
}
