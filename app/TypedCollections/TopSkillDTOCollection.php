<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\TopSkillDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, TopSkillDTO>
 */
class TopSkillDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [TopSkillDTO::class];

    /**
     * Find a skill by ID.
     */
    public function findBySkillId(int $skillId): ?TopSkillDTO
    {
        return $this->first(fn (TopSkillDTO $skill) => $skill->skillId === $skillId);
    }

    /**
     * Find a skill by name.
     */
    public function findBySkillName(string $skillName): ?TopSkillDTO
    {
        return $this->first(fn (TopSkillDTO $skill) => $skill->skillName === $skillName);
    }

    /**
     * Get total count of all matches.
     */
    public function getTotalMatchCount(): int
    {
        return $this->sum(fn (TopSkillDTO $skill) => $skill->count);
    }

    /**
     * Get total hits across all skills.
     */
    public function getTotalHits(): int
    {
        return $this->sum(fn (TopSkillDTO $skill) => $skill->hits);
    }

    /**
     * Sort by match count (descending).
     */
    public function sortByMatchCount(): self
    {
        return $this->sortByDesc(fn (TopSkillDTO $skill) => $skill->count);
    }

    /**
     * Sort by hit count (descending).
     */
    public function sortByHits(): self
    {
        return $this->sortByDesc(fn (TopSkillDTO $skill) => $skill->hits);
    }

    /**
     * Convert to array of arrays (for backward compatibility).
     *
     * @return array<int, array{skill_id: int, skill_name: string, count: int, hits: int}>
     */
    public function toArrayOfArrays(): array
    {
        return $this->map(fn (TopSkillDTO $skill) => $skill->toArray())->values()->all();
    }
}
