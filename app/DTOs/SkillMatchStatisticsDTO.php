<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SkillMatchStatisticsDTO
{
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
