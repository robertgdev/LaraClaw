<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of creating a symlink.
 *
 * Used by SetupWorkspaceInitializer::createAgentsSymlink() to return
 * the outcome of symlink creation.
 */
final readonly class SymlinkResultDTO
{
    public function __construct(
        public bool $created,
        public string $message,
    ) {}

    /**
     * Create a successful symlink result.
     */
    public static function created(string $message): self
    {
        return new self(created: true, message: $message);
    }

    /**
     * Create a skipped symlink result.
     */
    public static function skipped(string $message): self
    {
        return new self(created: false, message: $message);
    }
}
