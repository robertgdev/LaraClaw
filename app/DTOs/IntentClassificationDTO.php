<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class IntentClassificationDTO
{
    /**
     * @param  list<string>  $keywords
     * @param  array<string, mixed>  $entities
     */
    public function __construct(
        public string $intent,
        public float $confidence,
        public ?string $matchedSkill = null,
        public ?float $skillConfidence = null,
        public array $entities = [],
        public ?string $suggestedAgent = null,
        public ?string $reasoning = null,
        public ?string $method = null,
        public bool $fromCache = false,
        public array $keywords = [],
        public array $allScores = [],
    ) {}
}
