<?php

declare(strict_types=1);

namespace App\TypedCollections;

use App\DTOs\AgentSuggestionDTO;
use Gamez\Illuminate\Support\TypedCollection;

/**
 * @extends TypedCollection<array-key, AgentSuggestionDTO>
 */
class AgentSuggestionDTOCollection extends TypedCollection
{
    protected static array $allowedTypes = [AgentSuggestionDTO::class];

    /**
     * Get the best match (highest score).
     */
    public function getBestMatch(): ?AgentSuggestionDTO
    {
        return $this->sortByDesc('score')->first();
    }

    /**
     * Filter by minimum score.
     */
    public function withMinScore(float $minScore): self
    {
        return $this->filter(fn (AgentSuggestionDTO $suggestion) => $suggestion->score >= $minScore);
    }

    /**
     * Filter only high confidence suggestions.
     */
    public function highConfidence(float $threshold = 0.5): self
    {
        return $this->filter(fn (AgentSuggestionDTO $suggestion) => $suggestion->isHighConfidence($threshold));
    }

    /**
     * Get all agent IDs.
     *
     * @return array<string>
     */
    public function getAgentIds(): array
    {
        return $this->map(fn (AgentSuggestionDTO $suggestion) => $suggestion->agentId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Find suggestion for a specific agent.
     */
    public function forAgent(string $agentId): ?AgentSuggestionDTO
    {
        return $this->first(fn (AgentSuggestionDTO $suggestion) => $suggestion->agentId === $agentId);
    }
}
