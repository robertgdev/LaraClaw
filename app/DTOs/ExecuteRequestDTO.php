<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents an execute request extracted from an AI response.
 *
 * Used by ResponseParserService::extractExecuteRequests() to return
 * parsed execute requests without executing them.
 *
 * Supports formats:
 * - ```execute: scripts/schedule.sh create --cron "0 9 * * *"```
 * - [execute: scripts/schedule.sh create --cron "0 9 * * *"]
 */
final readonly class ExecuteRequestDTO
{
    /**
     * @param string $command The full command string
     * @param string|null $script The script path (null if not a script)
     * @param array<string> $args The arguments for the command
     */
    public function __construct(
        public string $command,
        public ?string $script,
        public array $args = [],
    ) {}

    /**
     * Check if this is a script execution request.
     */
    public function isScript(): bool
    {
        return $this->script !== null;
    }

    /**
     * Check if there are any arguments.
     */
    public function hasArgs(): bool
    {
        return ! empty($this->args);
    }
}
