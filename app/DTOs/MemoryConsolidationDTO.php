<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of memory consolidation operations.
 *
 * Used by MemoryEngineService::consolidate() and MemoryConsolidator::consolidate()
 * to return statistics about the consolidation process.
 */
final readonly class MemoryConsolidationDTO
{
    public function __construct(
        public int $decayed,
        public int $pruned,
        public int $merged,
    ) {}

    /**
     * Get total number of affected memories.
     */
    public function totalAffected(): int
    {
        return $this->decayed + $this->pruned + $this->merged;
    }

    /**
     * Check if any consolidation occurred.
     */
    public function hasChanges(): bool
    {
        return $this->totalAffected() > 0;
    }
}
