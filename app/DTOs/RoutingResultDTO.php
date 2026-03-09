<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object for routing results.
 *
 * Contains all information about how a message was routed to an agent,
 * including the routing method, classification, and skill suggestions.
 */
final readonly class RoutingResultDTO
{
    /**
     * @param  string  $agentId  The agent ID that will handle the message
     * @param  string  $message  The message to be processed (may be modified from original)
     * @param  bool  $isTeamRouted  Whether this was routed to a team
     * @param  string|null  $teamId  The team ID if team-routed
     * @param  string  $routingMethod  How the routing was determined (explicit, intent, skill, default, etc.)
     * @param  IntentClassificationDTO|null  $classification  The intent classification result
     * @param  list<string>  $suggestedSkills  List of suggested skill names
     * @param  array{agent_id: string, score: float, reasons: list<string>}|null  $agentSuggestion  Best agent match from suggestion
     */
    public function __construct(
        public string $agentId,
        public string $message,
        public bool $isTeamRouted = false,
        public ?string $teamId = null,
        public string $routingMethod = 'default',
        public ?IntentClassificationDTO $classification = null,
        public array $suggestedSkills = [],
        public ?array $agentSuggestion = null,
    ) {}

    /**
     * Check if this is an error response (e.g., multi-agent easter egg).
     */
    public function isError(): bool
    {
        return $this->agentId === 'error';
    }

    /**
     * Check if routing was explicit (@agent or @team prefix).
     */
    public function isExplicitRouting(): bool
    {
        return $this->routingMethod === 'explicit';
    }

    /**
     * Check if routing was based on intent classification.
     */
    public function isIntentRouting(): bool
    {
        return in_array($this->routingMethod, ['intent', 'intent_capability']);
    }

    /**
     * Check if routing was based on skill matching.
     */
    public function isSkillRouting(): bool
    {
        return $this->routingMethod === 'skill';
    }

    /**
     * Get the confidence score from classification if available.
     */
    public function getConfidence(): ?float
    {
        return $this->classification?->confidence;
    }

    /**
     * Get the intent from classification if available.
     */
    public function getIntent(): ?string
    {
        return $this->classification?->intent;
    }
}
