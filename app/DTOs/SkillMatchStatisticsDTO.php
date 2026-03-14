<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SkillMatchStatisticsDTO
{
    /**
     * @param  array<int, array{skill_id: int, skill_name: string, count: int, hits: int}>  $topSkills
     * @param  array<int, array{intent_category: string, count: int}>  $topCategories
     */
    public function __construct(
        public int $totalEntries,
        public int $totalHits,
        public float $avgConfidence,
        public int $highConfidenceCount,
        public array $topSkills,
        public array $topCategories,
        public int $recentEntries,
    ) {}
}
