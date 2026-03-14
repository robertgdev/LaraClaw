<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\TeammateMentionDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, TeammateMentionDTO>
 */
class TeammateMentionDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [TeammateMentionDTO::class];

    /**
     * Get all mentioned teammate IDs.
     *
     * @return array<string>
     */
    public function getTeammateIds(): array
    {
        return $this->map(fn (TeammateMentionDTO $mention) => $mention->teammateId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Find mentions for a specific teammate.
     */
    public function forTeammate(string $teammateId): self
    {
        return $this->filter(fn (TeammateMentionDTO $mention) => $mention->teammateId === $teammateId);
    }

    /**
     * Check if a teammate is mentioned.
     */
    public function mentionsTeammate(string $teammateId): bool
    {
        return $this->forTeammate($teammateId)->isNotEmpty();
    }
}
