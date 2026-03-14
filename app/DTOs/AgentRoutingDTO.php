<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Represents the result of parsing an @agent or @team routing prefix.
 *
 * Used by RoutingService::parseAgentRouting() to return routing information.
 */
final readonly class AgentRoutingDTO
{
    public function __construct(
        public string $agentId,
        public string $message,
        public bool $isTeam = false,
        public ?string $teamId = null,
    ) {}

    /**
     * Check if this is an error response (e.g., multi-agent easter egg).
     */
    public function isError(): bool
    {
        return $this->agentId === 'error';
    }

    /**
     * Check if this was routed to a team.
     */
    public function isTeamRouting(): bool
    {
        return $this->isTeam && $this->teamId !== null;
    }
}
