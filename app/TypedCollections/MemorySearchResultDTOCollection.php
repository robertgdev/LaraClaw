<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\MemorySearchResultDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, MemorySearchResultDTO>
 */
class MemorySearchResultDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [MemorySearchResultDTO::class];

    /**
     * Get results sorted by relevance (highest score first).
     */
    public function byRelevance(): self
    {
        return $this->sortByDesc('relevanceScore');
    }

    /**
     * Get the best match (highest score).
     */
    public function getBestMatch(): ?MemorySearchResultDTO
    {
        return $this->byRelevance()->first();
    }

    /**
     * Filter by minimum relevance score.
     */
    public function withMinScore(float $minScore): self
    {
        return $this->filter(fn (MemorySearchResultDTO $result) => $result->relevanceScore >= $minScore);
    }

    /**
     * Filter by memory source.
     */
    public function bySource(string $source): self
    {
        return $this->filter(fn (MemorySearchResultDTO $result) => $result->source === $source);
    }

    /**
     * Get episodic memories only.
     */
    public function episodic(): self
    {
        return $this->bySource('episodic');
    }

    /**
     * Get key-value memories only.
     */
    public function keyValue(): self
    {
        return $this->bySource('key_value');
    }

    /**
     * Get unique memory IDs.
     *
     * @return array<string>
     */
    public function getMemoryIds(): array
    {
        return $this->map(fn (MemorySearchResultDTO $result) => $result->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get all memory contents as an array.
     *
     * @return array<string>
     */
    public function getContents(): array
    {
        return $this->map(fn (MemorySearchResultDTO $result) => $result->content)
            ->values()
            ->all();
    }
}
