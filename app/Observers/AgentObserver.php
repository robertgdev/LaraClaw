<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Agent;
use App\Models\ConversationMessage;
use App\Models\Team;
use App\Services\SkillSearchService;

final readonly class AgentObserver
{
    public function __construct(
        private SkillSearchService $skillService
    ) {}

    /**
     * Handle the Agent "creating" event.
     * Autopopulate skills from available skills if not set.
     */
    public function creating(Agent $agent): void
    {
        if ($agent->skills === null) {
            $agent->skills = $this->getDefaultSkills();
        }

        if ($agent->capabilities === null) {
            $agent->capabilities = $this->inferCapabilities($agent);
        }
    }

    /**
     * Get default skills for a new agent.
     * Returns list of all available skill names.
     *
     * @return array<int, string>
     */
    private function getDefaultSkills(): array
    {
        $skills = $this->skillService->getAllSkills();

        return array_keys($skills);
    }

    /**
     * Infer capabilities from agent name/ID.
     *
     * @return array<int, string>
     */
    private function inferCapabilities(Agent $agent): array
    {
        $name = strtolower($agent->name.' '.$agent->agent_id);
        $capabilities = [];

        // Map keywords to capabilities
        $capabilityMap = [
            'coding' => ['coding', 'code', 'developer', 'dev', 'programmer'],
            'research' => ['research', 'researcher', 'analysis', 'analyst'],
            'creative' => ['creative', 'writer', 'content', 'copy'],
            'scheduling' => ['schedule', 'calendar', 'assistant', 'secretary'],
            'command' => ['agent', 'assistant', 'bot'],
        ];

        foreach ($capabilityMap as $capability => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    $capabilities[] = $capability;
                    break;
                }
            }
        }

        // Default capability for any agent
        if (empty($capabilities)) {
            $capabilities = ['conversation'];
        }

        return $capabilities;
    }

    public function deleting(Agent $agent): void
    {
        if (! $agent->isForceDeleting()) {
            $agent->messages()->delete();
            $agent->ledTeams()->delete();
        }
    }

    public function deleted(Agent $agent): void
    {
        $agent->teams()->detach();
    }

    public function restored(Agent $agent): void
    {
        ConversationMessage::withTrashed()->where('agent_id', $agent->agent_id)->restore();
        Team::withTrashed()->where('leader_agent_id', $agent->agent_id)->restore();
    }
}
