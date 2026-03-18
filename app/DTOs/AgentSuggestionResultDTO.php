<?php

declare(strict_types=1);

namespace App\DTOs;

use App\TypedCollections\AgentSuggestionDTOCollection;

/**
 * Represents the complete result of agent suggestion analysis.
 *
 * Used by IntentClassificationService::suggestAgent() and
 * AgentSuggestionService::suggest() to return the full analysis.
 */
final readonly class AgentSuggestionResultDTO
{
    /**
     * @param IntentClassificationDTO $classification The intent classification result
     * @param ExtractedEntitiesDTO $entities The extracted entities
     * @param AgentSuggestionDTOCollection $suggestions List of agent suggestions sorted by score
     * @param AgentSuggestionDTO|null $bestMatch The best matching agent (null if no matches)
     */
    public function __construct(
        public IntentClassificationDTO $classification,
        public ExtractedEntitiesDTO $entities,
        public AgentSuggestionDTOCollection $suggestions,
        public ?AgentSuggestionDTO $bestMatch = null,
    ) {}

    /**
     * Check if any suggestions were found.
     */
    public function hasSuggestions(): bool
    {
        return ! $this->suggestions->isEmpty();
    }

    /**
     * Check if there's a best match.
     */
    public function hasBestMatch(): bool
    {
        return $this->bestMatch !== null;
    }

    /**
     * Check if the best match is high confidence.
     */
    public function isHighConfidence(float $threshold = 0.5): bool
    {
        return $this->bestMatch?->isHighConfidence($threshold) ?? false;
    }

    /**
     * Get the number of suggestions.
     */
    public function count(): int
    {
        return $this->suggestions->count();
    }
}
