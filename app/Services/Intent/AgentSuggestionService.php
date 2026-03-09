<?php

declare(strict_types=1);

namespace App\Services\Intent;

use App\DTOs\IntentClassificationDTO;
use App\Services\IntentClassificationService;
use App\TypedCollections\AgentCollection;

/**
 * AgentSuggestionService - Suggests the best agent for a given message.
 *
 * Scores agents against intent classifications and skill matches
 * to recommend the best agent for handling a particular request.
 */
class AgentSuggestionService
{
    protected IntentClassificationService $classificationService;

    protected EntityExtractor $entityExtractor;

    public function __construct(
        IntentClassificationService $classificationService,
        EntityExtractor $entityExtractor
    ) {
        $this->classificationService = $classificationService;
        $this->entityExtractor = $entityExtractor;
    }

    /**
     * Get suggested agent based on intent and entities.
     *
     * @param  string  $message  The user message
     * @param  AgentCollection  $agents  Collection of Agent models (keyed by agent_id)
     * @return array{classification: IntentClassificationDTO, entities: array, suggestions: array, best_match: array|null}
     */
    public function suggest(string $message, AgentCollection $agents): array
    {
        $classification = $this->classificationService->classify($message);
        $entities = $this->entityExtractor->extract($message);

        $suggestions = [];

        foreach ($agents as $agentId => $agent) {
            $score = 0;
            $reasons = [];

            // Get capabilities from Agent model (JSON column cast to array)
            $capabilities = $agent->capabilities ?? [];

            // Match intent to capabilities
            if (in_array($classification->intent, $capabilities)) {
                $score += 0.5;
                $reasons[] = "Matches intent: {$classification->intent}";
            }

            // Get skills from Agent model (JSON column cast to array)
            $skills = $agent->skills ?? [];

            // Check if message mentions a skill this agent has
            foreach ($skills as $skill) {
                if (str_contains(strtolower($message), strtolower($skill))) {
                    $score += 0.3;
                    $reasons[] = "Has skill: {$skill}";
                }
            }

            // Use matched skill from classification
            if (! empty($classification->matchedSkill)) {
                if (in_array($classification->matchedSkill, $skills)) {
                    $score += 0.5;
                    $reasons[] = "Has matched skill: {$classification->matchedSkill}";
                }
            }

            if ($score > 0) {
                $suggestions[] = [
                    'agent_id' => $agentId,
                    'score' => $score,
                    'reasons' => $reasons,
                ];
            }
        }

        // Sort by score descending
        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'classification' => $classification,
            'entities' => $entities,
            'suggestions' => $suggestions,
            'best_match' => $suggestions[0] ?? null,
        ];
    }
}
