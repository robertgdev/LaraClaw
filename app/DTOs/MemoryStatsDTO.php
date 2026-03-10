<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class MemoryStatsDTO
{
    public function __construct(
        public int $total,
        public float $avgImportance,
        public int $oldCount,
        public int $pruneCandidates,
    ) {}
}

