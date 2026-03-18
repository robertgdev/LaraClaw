<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a skill search result with scoring.
 *
 * Used by SkillSearchService::search(), suggestSkillsForMessage(),
 * findBestMatch(), and findSkillForMessage() to return search results.
 */
final readonly class SkillSearchResultDTO
{
    /**
     * @param SkillDTO $skill The matched skill
     * @param int $score The search score
     * @param array<string> $matchedKeywords Keywords that matched the query
     * @param bool $fromCache Whether this result came from cache
     * @param int|null $cacheHitId The cache hit ID if from cache
     */
    public function __construct(
        public SkillDTO $skill,
        public int $score,
        public array $matchedKeywords = [],
        public bool $fromCache = false,
        public ?int $cacheHitId = null,
    ) {}

    /**
     * Check if this is a high-quality match.
     */
    public function isHighQuality(int $threshold = 5): bool
    {
        return $this->score >= $threshold;
    }

    /**
     * Get normalized confidence score (0.0 to 1.0).
     */
    public function getConfidence(): float
    {
        return min(1.0, $this->score / 20);
    }

    /**
     * Create from an array (for backward compatibility).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $skillData = $data['skill'] ?? [];
        
        return new self(
            skill: SkillDTO::fromArray($skillData),
            score: $data['score'] ?? 0,
            matchedKeywords: $data['matched_keywords'] ?? [],
            fromCache: $data['from_cache'] ?? false,
            cacheHitId: $data['cache_hit_id'] ?? null,
        );
    }

    /**
     * Convert to array (for backward compatibility).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill->toArray(),
            'score' => $this->score,
            'matched_keywords' => $this->matchedKeywords,
            'from_cache' => $this->fromCache,
            'cache_hit_id' => $this->cacheHitId,
        ];
    }
}
