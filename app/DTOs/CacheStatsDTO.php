<?php

namespace App\DTOs;

final readonly class CacheStatsDTO
{
    public function __construct(
        public int $totalEntries,
        public int $totalHits,
        public int $skillsCovered,
        public int $skillsPending,
        public int $skillsClassified,
        public int $skillsFailed,
    ) {}
}
