<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\SkillSearchResultDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, SkillSearchResultDTO>
 */
class SkillSearchResultDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [SkillSearchResultDTO::class];

    /**
     * Get the best match (highest score).
     */
    public function getBestMatch(): ?SkillSearchResultDTO
    {
        return $this->sortByDesc('score')->first();
    }

    /**
     * Filter by minimum score.
     */
    public function withMinScore(int $minScore): self
    {
        return $this->filter(fn (SkillSearchResultDTO $result) => $result->score >= $minScore);
    }

    /**
     * Filter only high quality matches.
     */
    public function highQuality(int $threshold = 5): self
    {
        return $this->withMinScore($threshold);
    }

    /**
     * Get all matched keywords across all results.
     *
     * @return array<string>
     */
    public function getAllMatchedKeywords(): array
    {
        $keywords = [];
        foreach ($this as $result) {
            $keywords = array_merge($keywords, $result->matchedKeywords);
        }

        return array_values(array_unique($keywords));
    }
}
