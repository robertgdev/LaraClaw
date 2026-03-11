<?php

namespace App\DTOs;

final readonly class SkillClassificationResultDTO
{
    public function __construct(
        public int $skillsProcessed,
        public int $skillsSkipped,
        public int $mappingsGenerated,
        public int $mappingsStored,
        /** @var array<string, array> */
        public array $skillsDetails = [],
        /** @var string[] */
        public array $errors = []
    ) {}
}
