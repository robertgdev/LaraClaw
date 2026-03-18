<?php

namespace App\DTOs;

final readonly class SkillClassificationStatsDTO
{
    public function __construct(
        public int $total,
        public int $pending,
        public int $classified,
        public int $failed,
        public int $totalIntents,
    ) {}
}
