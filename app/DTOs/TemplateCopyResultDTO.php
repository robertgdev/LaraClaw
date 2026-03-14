<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of copying template files.
 *
 * Used by SetupWorkspaceInitializer::copyTemplateFilesToStorage() to return
 * statistics about the copy operation.
 */
final readonly class TemplateCopyResultDTO
{
    /**
     * @param int $copied Number of files copied
     * @param int $skipped Number of files skipped (already existed)
     * @param array<string> $messages Messages describing what was done
     */
    public function __construct(
        public int $copied,
        public int $skipped,
        public array $messages = [],
    ) {}

    /**
     * Get total number of files processed.
     */
    public function total(): int
    {
        return $this->copied + $this->skipped;
    }

    /**
     * Check if any files were copied.
     */
    public function hasCopied(): bool
    {
        return $this->copied > 0;
    }
}
