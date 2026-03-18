<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of syncing skills from index.
 *
 * Used by Skill::syncFromIndex() and SkillSyncService::syncFromIndex()
 * to return statistics about the sync operation.
 */
final readonly class SkillSyncResultDTO
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $deactivated,
    ) {}

    /**
     * Get total number of skills processed.
     */
    public function total(): int
    {
        return $this->created + $this->updated + $this->deactivated;
    }

    /**
     * Check if any changes were made.
     */
    public function hasChanges(): bool
    {
        return $this->total() > 0;
    }

    /**
     * Convert to array (for logging and serialization).
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deactivated' => $this->deactivated,
        ];
    }
}
