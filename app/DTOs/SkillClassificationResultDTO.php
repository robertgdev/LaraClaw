<?php

namespace App\DTOs;

final readonly class SkillClassificationResultDTO
{
    /**
     * @param  array<string, array{intents: array<int, string>, keywords: array<int, string>}>  $skillsDetails
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public int $skillsProcessed,
        public int $skillsSkipped,
        public int $mappingsGenerated,
        public int $mappingsStored,
        public array $skillsDetails = [],
        public array $errors = []
    ) {}
}
