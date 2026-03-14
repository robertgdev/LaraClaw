<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a top skill with its match statistics.
 *
 * Used by SkillMatchStatisticsService::getTopSkills() to return
 * structured skill statistics data instead of arrays.
 */
final readonly class TopSkillDTO
{
    /**
     * @param int $skillId The skill ID
     * @param string $skillName The skill name
     * @param int $count Number of matches for this skill
     * @param int $hits Total hit count for this skill
     */
    public function __construct(
        public int $skillId,
        public string $skillName,
        public int $count,
        public int $hits,
    ) {}

    /**
     * Create from an array (for backward compatibility).
     *
     * @param array{skill_id: int, skill_name: string, count: int, hits: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            skillId: $data['skill_id'],
            skillName: $data['skill_name'],
            count: $data['count'],
            hits: $data['hits'],
        );
    }

    /**
     * Convert to array (for backward compatibility).
     *
     * @return array{skill_id: int, skill_name: string, count: int, hits: int}
     */
    public function toArray(): array
    {
        return [
            'skill_id' => $this->skillId,
            'skill_name' => $this->skillName,
            'count' => $this->count,
            'hits' => $this->hits,
        ];
    }
}
