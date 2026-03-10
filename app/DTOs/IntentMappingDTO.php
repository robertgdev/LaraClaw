<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class IntentMappingDTO
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $sampleIntent,
        public array $keywords,
        public ?int $skillId,
        public float $confidence,
        public string $category,
    ) {}

    public static function fromArray(array $item, ?int $skillId): self
    {
        return new self(
            sampleIntent: $item['sampleIntent'],
            keywords: $item['keywords'] ?? [],
            skillId: $skillId,
            confidence: (float) ($item['confidence'] ?? 0.8),
            category: $item['category'] ?? 'unknown',
        );
    }
}
