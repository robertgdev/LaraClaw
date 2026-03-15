<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a single memory search result.
 *
 * Used by MemoryEngineService::search() to return search results
 * with relevance scoring.
 */
final readonly class MemorySearchResultDTO
{
    public function __construct(
        public int $id,
        public string $content,
        public float $relevanceScore,
        public string $source,
    ) {}

    /**
     * Check if this is an episodic memory result.
     */
    public function isEpisodic(): bool
    {
        return $this->source === 'episodic';
    }

    /**
     * Check if this is a key-value memory result.
     */
    public function isKeyValue(): bool
    {
        return $this->source === 'key_value';
    }
}
