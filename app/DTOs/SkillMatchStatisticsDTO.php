<?php

declare(strict_types=1);

namespace App\DTOs;

use App\TypedCollections\TopSkillDTOCollection;

final readonly class SkillMatchStatisticsDTO
{
    /**
     * @param  TopSkillDTOCollection  $topSkills
     * @param  array<int, array{intent_category: string, count: int}>  $topCategories
     */
    public function __construct(
        public int $totalEntries,
        public int $totalHits,
        public float $avgConfidence,
        public int $highConfidenceCount,
        public TopSkillDTOCollection $topSkills,
        public array $topCategories,
        public int $recentEntries,
    ) {}
}
