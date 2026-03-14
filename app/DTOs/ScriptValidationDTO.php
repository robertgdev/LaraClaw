<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of validating a script execution request.
 *
 * Used by ResponseParserService::validateExecuteRequests() to return
 * validation results without executing the scripts.
 */
final readonly class ScriptValidationDTO
{
    /**
     * @param bool $valid Whether the script is valid for execution
     * @param string $command The original command that was validated
     * @param string|null $error Error message if validation failed
     */
    public function __construct(
        public bool $valid,
        public string $command,
        public ?string $error = null,
    ) {}

    /**
     * Create a valid validation result.
     */
    public static function valid(string $command): self
    {
        return new self(valid: true, command: $command, error: null);
    }

    /**
     * Create an invalid validation result.
     */
    public static function invalid(string $command, string $error): self
    {
        return new self(valid: false, command: $command, error: $error);
    }
}
