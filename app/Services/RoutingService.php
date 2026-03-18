<?php

namespace App\Services;

use App\DTOs\AgentRoutingDTO;
use App\DTOs\RoutingResultDTO;
use App\DTOs\SkillSearchResultDTO;
use App\DTOs\TeammateMentionDTO;
use App\Logging\MultiLogger;
use App\Models\Agent;
use App\Models\Team;
use App\TypedCollections\AgentCollection;
use App\TypedCollections\SkillSearchResultDTOCollection;
use App\TypedCollections\TeamCollection;
use App\TypedCollections\TeammateMentionDTOCollection;

use function Safe\preg_match;
use function Safe\preg_match_all;
use function Safe\preg_replace;

class RoutingService
{
    protected SettingsService $settings;

    protected IntentClassificationService $intentService;

    protected SkillSearchService $skillService;

    public function __construct(
        SettingsService $settings,
        IntentClassificationService $intentService,
        SkillSearchService $skillService
    ) {
        $this->settings = $settings;
        $this->intentService = $intentService;
        $this->skillService = $skillService;
    }

    /**
     * Find the first team that contains the given agent.
     */
    public function findTeamForAgent(string $agentId): ?Team
    {
        return $this->settings->findTeamForAgent($agentId);
    }

    /**
     * Check if a mentioned ID is a valid teammate of the current agent in the given team.
     */
    public function isTeammate(
        string $mentionedId,
        string $currentAgentId,
        string $teamId,
        TeamCollection $teams,
        AgentCollection $agents
    ): bool {
        $team = $teams[$teamId] ?? null;
        if (! $team) {
            return false;
        }

        // Get agent IDs from the team model
        $agentIds = $team->getAgentIds();

        return $mentionedId !== $currentAgentId
            && in_array($mentionedId, $agentIds)
            && isset($agents[$mentionedId]);
    }

    /**
     * Check if a mentioned ID is a valid teammate using model relationships.
     */
    public function isTeammateUsingModel(
        string $mentionedId,
        string $currentAgentId,
        Team $team
    ): bool {
        return $mentionedId !== $currentAgentId
            && $team->hasAgent($mentionedId)
            && Agent::where('agent_id', $mentionedId)->where('is_active', true)->exists();
    }

    /**
     * Extract teammate mentions from a response text.
     */
    public function extractTeammateMentions(
        string $response,
        string $currentAgentId,
        string $teamId,
        TeamCollection $teams,
        AgentCollection $agents
    ): TeammateMentionDTOCollection {
        $results = [];
        $seen = [];

        // Tag format: [@agent_id: message] or [@agent1,agent2: message]
        $tagRegex = '/\[@(\S+?):\s*([\s\S]*?)\]/';

        if (! preg_match_all($tagRegex, $response, $matches, PREG_SET_ORDER)) {
            return new TeammateMentionDTOCollection([]);
        }

        // Strip all [@teammate: ...] tags from the full response to get shared context
        $sharedContext = preg_replace($tagRegex, '', $response);
        $sharedContext = trim($sharedContext);

        foreach ($matches as $match) {
            $directMessage = trim($match[2]);
            $fullMessage = $sharedContext
                ? "{$sharedContext}\n\n------\n\nDirected to you:\n{$directMessage}"
                : $directMessage;

            // Support comma-separated agent IDs: [@coder,reviewer: message]
            $candidateIds = array_map('trim', explode(',', strtolower($match[1])));
            $candidateIds = array_filter($candidateIds);

            foreach ($candidateIds as $candidateId) {
                if (! isset($seen[$candidateId]) && $this->isTeammate($candidateId, $currentAgentId, $teamId, $teams, $agents)) {
                    $results[] = new TeammateMentionDTO(
                        teammateId: $candidateId,
                        message: $fullMessage,
                    );
                    $seen[$candidateId] = true;
                }
            }
        }

        return new TeammateMentionDTOCollection($results);
    }

    /**
     * Get the reset flag path for a specific agent.
     */
    public function getAgentResetFlag(string $agentId): string
    {
        $workspacePath = $this->settings->getWorkspacePath();

        return $workspacePath.'/'.$agentId.'/reset_flag';
    }

    /**
     * Detect if message mentions multiple agents (easter egg for future feature).
     *
     * @return array<string>
     */
    public function detectMultipleAgents(string $message, AgentCollection $agents, TeamCollection $teams): array
    {
        preg_match_all('/@(\S+)/', $message, $matches);
        $mentions = $matches[0];
        $validAgents = [];

        foreach ($mentions as $mention) {
            $agentId = strtolower(substr($mention, 1));
            if (isset($agents[$agentId])) {
                $validAgents[] = $agentId;
            }
        }

        // If multiple agents are all in the same team, don't trigger easter egg
        if (count($validAgents) > 1) {
            foreach ($teams as $team) {
                $teamAgentIds = $team->getAgentIds();
                $allInTeam = true;
                foreach ($validAgents as $agentId) {
                    if (! in_array($agentId, $teamAgentIds)) {
                        $allInTeam = false;
                        break;
                    }
                }
                if ($allInTeam) {
                    return []; // Same team — chain will handle collaboration
                }
            }
        }

        return $validAgents;
    }

    /**
     * Main routing method - determines agent, team, and skills for a message.
     */
    public function route(string $rawMessage, ?AgentCollection $agents = null, ?TeamCollection $teams = null): RoutingResultDTO
    {
        $agents = $agents ?: $this->settings->getAgents();
        $teams = $teams ?: $this->settings->getTeams();
        $defaultAgentId = $this->settings->getDefaultAgentId();

        // Step 1: Check for explicit @agent_id or @team_id routing
        $explicitRouting = $this->parseAgentRouting($rawMessage, $agents, $teams);

        if ($explicitRouting->agentId !== $defaultAgentId) {
            // Explicit routing takes precedence
            return new RoutingResultDTO(
                agentId: $explicitRouting->agentId,
                message: $explicitRouting->message,
                isTeamRouted: $explicitRouting->isTeam,
                teamId: $explicitRouting->teamId,
                routingMethod: 'explicit',
                classification: null,
                suggestedSkills: [],
            );
        }

        // Step 2: Classify intent for intelligent routing
        $classification = $this->intentService->classify($rawMessage);

        // Step 3: Find relevant skills
        $skillResults = $this->skillService->suggestSkillsForMessage($rawMessage, [
            'intent' => $classification->intent,
        ]);

        // Step 4: Find best agent based on intent and skills
        $agentSuggestion = $this->intentService->suggestAgent($rawMessage, $agents);

        // Step 5: Determine final routing
        $agentId = $defaultAgentId;
        $teamId = null;
        $isTeamRouted = false;
        $routingMethod = 'default';

        // If we have a strong agent suggestion, use it
        $bestMatch = $agentSuggestion->bestMatch;
        if ($bestMatch && $bestMatch->score >= 0.5) {
            $agentId = $bestMatch->agentId;
            $routingMethod = 'intent';
        }

        // Check if any agent has matching capabilities for the intent
        if ($agentId === $defaultAgentId && $classification->confidence >= 0.7) {
            $intentAgent = $this->findAgentForIntent($classification->intent, $agents);
            if ($intentAgent) {
                $agentId = $intentAgent;
                $routingMethod = 'intent_capability';
            }
        }

        // Check if any agent has matching skills
        if ($agentId === $defaultAgentId && $skillResults->isNotEmpty()) {
            $skillAgent = $this->findAgentForSkills($skillResults, $agents);
            if ($skillAgent) {
                $agentId = $skillAgent;
                $routingMethod = 'skill';
            }
        }

        // Log routing decision
        MultiLogger::info('Routing decision', [
            'message_preview' => substr($rawMessage, 0, 50),
            'agent_id' => $agentId,
            'routing_method' => $routingMethod,
            'intent' => $classification->intent,
            'intent_confidence' => $classification->confidence,
        ]);

        return new RoutingResultDTO(
            agentId: $agentId,
            message: $rawMessage,
            isTeamRouted: $isTeamRouted,
            teamId: $teamId,
            routingMethod: $routingMethod,
            classification: $classification,
            suggestedSkills: $skillResults->map(fn (SkillSearchResultDTO $s) => $s->skill->name)->all(),
            agentSuggestion: $bestMatch ? [
                'agent_id' => $bestMatch->agentId,
                'score' => $bestMatch->score,
                'reasons' => $bestMatch->reasons,
            ] : null,
        );
    }

    /**
     * Parse @agent_id or @team_id prefix from a message.
     */
    public function parseAgentRouting(string $rawMessage, AgentCollection $agents, TeamCollection $teams): AgentRoutingDTO
    {
        $agents = $agents->isNotEmpty() ? $agents : $this->settings->getAgents();
        $teams = $teams->isNotEmpty() ? $teams : $this->settings->getTeams();
        $defaultAgentId = $this->settings->getDefaultAgentId();

        // Easter egg: Check for multiple agent mentions (only for agents NOT in the same team)
        $mentionedAgents = $this->detectMultipleAgents($rawMessage, $agents, $teams);

        if (count($mentionedAgents) > 1) {
            $agentList = implode(', ', array_map(fn ($t) => '@'.$t, $mentionedAgents));

            return new AgentRoutingDTO(
                agentId: 'error',
                message: "🚀 **Agent-to-Agent Collaboration - Coming Soon!**\n\n".
                    "You mentioned multiple agents: {$agentList}\n\n".
                    "Right now, I can only route to one agent at a time. But we're working on something cool:\n\n".
                    "✨ **Multi-Agent Coordination** - Agents will be able to collaborate on complex tasks!\n".
                    "✨ **Smart Routing** - Send instructions to multiple agents at once!\n".
                    "✨ **Agent Handoffs** - One agent can delegate to another!\n\n".
                    "For now, please send separate messages to each agent:\n".
                    implode("\n", array_map(fn ($t) => "• `@{$t} [your message]`", $mentionedAgents))."\n\n".
                    '_Stay tuned for updates! 🎉_',
            );
        }

        if (! preg_match('/^@(\S+)\s+([\s\S]*)$/', $rawMessage, $match)) {
            return new AgentRoutingDTO(
                agentId: $defaultAgentId,
                message: $rawMessage,
            );
        }

        $candidateId = strtolower($match[1]);
        $message = $match[2];

        // Check agent IDs
        if (isset($agents[$candidateId])) {
            return new AgentRoutingDTO(
                agentId: $candidateId,
                message: $message,
            );
        }

        // Check team IDs — resolve to leader agent
        if (isset($teams[$candidateId])) {
            return new AgentRoutingDTO(
                agentId: $teams[$candidateId]->leader_agent_id,
                message: $message,
                isTeam: true,
                teamId: $candidateId,
            );
        }

        // Match by agent name (case-insensitive)
        foreach ($agents as $id => $agent) {
            if (strtolower($agent->name) === $candidateId) {
                return new AgentRoutingDTO(
                    agentId: $id,
                    message: $message,
                );
            }
        }

        // Match by team name (case-insensitive)
        foreach ($teams as $id => $team) {
            if (strtolower($team->name) === $candidateId) {
                return new AgentRoutingDTO(
                    agentId: $team->leader_agent_id,
                    message: $message,
                    isTeam: true,
                    teamId: $id,
                );
            }
        }

        return new AgentRoutingDTO(
            agentId: $defaultAgentId,
            message: $rawMessage,
        );
    }

    /**
     * Find an agent that handles a specific intent.
     */
    protected function findAgentForIntent(string $intent, AgentCollection $agents): ?string
    {
        foreach ($agents as $agentId => $agent) {
            $capabilities = $agent->capabilities ?? [];
            if (in_array($intent, $capabilities)) {
                return $agentId;
            }
        }

        return null;
    }

    /**
     * Find an agent that has relevant skills.
     */
    protected function findAgentForSkills(SkillSearchResultDTOCollection $skillResults, AgentCollection $agents): ?string
    {
        foreach ($skillResults as $result) {
            $skillName = $result->skill->name;

            foreach ($agents as $agentId => $agent) {
                $agentSkills = $agent->skills ?? [];
                if (in_array($skillName, $agentSkills)) {
                    return $agentId;
                }
            }
        }

        return null;
    }

    /**
     * Get all agents.
     */
    public function getAgents(): AgentCollection
    {
        return $this->settings->getAgents();
    }

    /**
     * Get all teams.
     */
    public function getTeams(): TeamCollection
    {
        return $this->settings->getTeams();
    }

    /**
     * Get a specific agent by ID.
     */
    public function getAgent(string $agentId): ?Agent
    {
        return $this->settings->getAgent($agentId);
    }

    /**
     * Get the intent classification service.
     */
    public function getIntentService(): IntentClassificationService
    {
        return $this->intentService;
    }

    /**
     * Get the skill search service.
     */
    public function getSkillService(): SkillSearchService
    {
        return $this->skillService;
    }
}
