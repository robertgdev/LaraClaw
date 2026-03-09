<?php

declare(strict_types=1);

namespace App\Services\Prompt;

use App\TypedCollections\AgentCollection;
use App\TypedCollections\TeamCollection;

/**
 * Injects teammate information into AGENTS.md between markers.
 * Also extracts teammate lists from team configurations.
 */
class TeammatePromptInjector
{
    /**
     * Inject teammate information into AGENTS.md between markers.
     *
     * @param  string  $content  The AGENTS.md content
     * @param  string|null  $agentId  The current agent's ID
     * @param  string|null  $agentName  The current agent's display name
     * @param  string|null  $agentModel  The current agent's model
     * @param  array  $teammates  List of teammates
     * @return string The modified content
     */
    public function inject(
        string $content,
        ?string $agentId,
        ?string $agentName,
        ?string $agentModel,
        array $teammates
    ): string {
        $startMarker = '<!-- TEAMMATES_START -->';
        $endMarker = '<!-- TEAMMATES_END -->';
        $startIdx = strpos($content, $startMarker);
        $endIdx = strpos($content, $endMarker);

        if ($startIdx === false || $endIdx === false) {
            return $content;
        }

        $block = '';

        // Add self info
        if ($agentId !== null) {
            $displayName = $agentName ?? $agentId;
            $displayModel = $agentModel ?? 'unknown';
            $block .= "\n### You\n\n- `@{$agentId}` — **{$displayName}** ({$displayModel})\n";
        }

        // Add teammates
        if (! empty($teammates)) {
            $block .= "\n### Your Teammates\n\n";
            foreach ($teammates as $teammate) {
                $tid = $teammate['id'] ?? 'unknown';
                $tname = $teammate['name'] ?? $tid;
                $tmodel = $teammate['model'] ?? 'unknown';
                $block .= "- `@{$tid}` — **{$tname}** ({$tmodel})\n";
            }
        }

        // Replace content between markers
        return substr($content, 0, $startIdx + strlen($startMarker))
            .$block
            .substr($content, $endIdx);
    }

    /**
     * Extract teammate information from teams for a given agent.
     *
     * @param  string  $agentId  The agent ID to find teammates for
     * @param  AgentCollection  $agents  All available agents
     * @param  TeamCollection  $teams  All available teams
     * @return array List of teammates with id, name, and model
     */
    public function extractTeammates(string $agentId, AgentCollection $agents, TeamCollection $teams): array
    {
        $teammates = [];

        foreach ($teams as $team) {
            $teamAgentIds = $team->getAgentIds();

            if (! in_array($agentId, $teamAgentIds)) {
                continue;
            }

            foreach ($teamAgentIds as $tid) {
                if ($tid === $agentId) {
                    continue;
                }

                $agent = $agents[$tid] ?? null;
                if ($agent && ! isset($teammates[$tid])) {
                    $teammates[$tid] = [
                        'id' => $tid,
                        'name' => $agent['name'] ?? $tid,
                        'model' => $agent['model'] ?? 'unknown',
                    ];
                }
            }
        }

        return array_values($teammates);
    }
}
