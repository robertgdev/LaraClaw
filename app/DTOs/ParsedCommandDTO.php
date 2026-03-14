<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a parsed command with script path and arguments.
 *
 * Used by ScriptPathResolver to return parsed command components.
 */
final readonly class ParsedCommandDTO
{
    /**
     * @param string|null $script The script path (null if empty command)
     * @param array<string> $args The arguments passed to the script
     */
    public function __construct(
        public ?string $script,
        public array $args = [],
    ) {}

    /**
     * Check if a script was parsed.
     */
    public function hasScript(): bool
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
