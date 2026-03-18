<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents a single agent suggestion with score and reasons.
 *
 * Used as part of AgentSuggestionResultDTO to rank agent matches.
 */
final readonly class AgentSuggestionDTO
{
    /**
     * @param string $agentId The agent's unique identifier
     * @param float $score The suggestion score (0.0 to 1.0)
     * @param array<string> $reasons List of reasons why this agent was suggested
     */
    public function __construct(
        public string $agentId,
        public float $score,
        public array $reasons = [],
    ) {}

    /**
     * Check if this suggestion has a high confidence score.
     */
    public function isHighConfidence(float $threshold = 0.5): bool
    {
        return $this->score >= $threshold;
    }
}
