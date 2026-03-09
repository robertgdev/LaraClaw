<?php

declare(strict_types=1);

namespace App\Services\Setup;

use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Handles agent configuration during the setup wizard.
 *
 * Manages default agent creation, additional agent creation,
 * and default agent selection.
 */
class SetupAgentConfigurator
{
    /**
     * Configure the workspace (name and path).
     *
     * @return array{workspace_name: string, workspace_path: string}
     */
    public function configureWorkspace(): array
    {
        info('Step 4: Workspace Configuration');

        $defaultName = 'laraclaw-workspace';
        $name = text(
            label: 'Workspace name',
            placeholder: $defaultName,
            default: $defaultName,
            validate: fn ($value) => preg_match('/^[a-zA-Z0-9_-]+$/', $value) ? null : 'Name must contain only letters, numbers, hyphens, and underscores'
        );

        // Clean workspace name
        $name = Str::slug($name, '-');
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?: $defaultName;
        $path = storage_path("app/$name");

        return [
            'workspace_name' => $name,
            'workspace_path' => $path,
        ];
    }

    /**
     * Configure the default agent.
     *
     * @return array{default_agent_id: string, default_agent_name: string}
     */
    public function configureDefaultAgent(): array
    {
        info('Step 5: Default Agent');

        $defaultName = 'assistant';
        $name = text(
            label: 'Default agent name',
            placeholder: $defaultName,
            default: $defaultName,
            validate: fn ($value) => preg_match('/^[a-zA-Z0-9_-]+$/', $value) ? null : 'Name must contain only letters, numbers, hyphens, and underscores'
        );

        // Clean agent name
        $agentId = Str::slug($name, '-');
        $agentId = preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId) ?: $defaultName;
        $agentId = strtolower($agentId);

        $displayName = ucfirst($agentId);

        return [
            'default_agent_id' => $agentId,
            'default_agent_name' => $displayName,
        ];
    }

    /**
     * Configure additional agents (optional).
     *
     * @param  string  $defaultAgentId  The default agent ID (to prevent duplicates)
     * @return array<int, array{agent_id: string, name: string, provider: string, model: string}>
     */
    public function configureAdditionalAgents(string $defaultAgentId): array
    {
        $agents = [];

        if (! confirm('Set up additional agents?', false)) {
            return $agents;
        }

        $providers = config('laraclaw.providers', []);
        $adding = true;

        while ($adding) {
            $agentId = text(
                label: 'Agent ID (lowercase, no spaces)',
                placeholder: 'e.g., coder, reviewer, tester',
                validate: fn ($value) => preg_match('/^[a-zA-Z0-9_-]+$/', $value) ? null : 'ID must contain only letters, numbers, hyphens, and underscores'
            );

            $agentId = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $agentId));

            if (empty($agentId)) {
                error('Invalid ID, skipping');

                continue;
            }

            if (in_array($agentId, ['system', 'all']) || $agentId === $defaultAgentId) {
                error("Agent ID '{$agentId}' is reserved or already used");

                continue;
            }

            foreach ($agents as $existingAgent) {
                if ($existingAgent['agent_id'] === $agentId) {
                    error("Agent ID '{$agentId}' is already used");

                    continue 2;
                }
            }

            $displayName = text(
                label: 'Display name',
                placeholder: ucfirst($agentId),
                default: ucfirst($agentId)
            );

            // Provider choice
            $textProviders = array_filter($providers, fn ($p) => $p['supports_text'] ?? true);
            $providerOptions = collect($textProviders)->mapWithKeys(
                fn ($p, $id) => [$id => $p['display']]
            )->toArray();

            $providerId = select(
                label: 'Provider',
                options: $providerOptions,
                default: array_key_first($textProviders)
            );
            $provider = $providers[$providerId];

            // Model choice
            $modelId = select(
                label: 'Model',
                options: $provider['models'],
                default: $provider['default_model']
            );

            $agents[] = [
                'agent_id' => $agentId,
                'name' => $displayName,
                'provider' => $providerId,
                'model' => $modelId,
            ];

            info("Agent '@{$agentId}' added");

            $adding = confirm('Add another agent?', false);
        }

        return $agents;
    }

    /**
     * Select which agent should be the default for routing.
     *
     * @param  array  $config  Current config with default_agent_id, default_agent_name, provider, model, additional_agents
     * @return string The selected default agent ID
     */
    public function selectDefaultAgent(array $config): string
    {
        info('Default Agent Selection');

        // Build list of all agents
        $allAgents = [
            [
                'agent_id' => $config['default_agent_id'],
                'name' => $config['default_agent_name'],
                'provider' => $config['provider'],
                'model' => $config['model'],
            ],
        ];

        foreach ($config['additional_agents'] as $agent) {
            $allAgents[] = $agent;
        }

        // Build agent options for select
        $agentOptions = [];
        foreach ($allAgents as $index => $agent) {
            $default = $index === 0 ? ' (current default)' : '';
            $agentOptions[$agent['agent_id']] = "@{$agent['agent_id']} - {$agent['name']} [{$agent['provider']}/{$agent['model']}]{$default}";
        }

        $selectedAgentId = select(
            label: 'Which agent should handle messages without an @mention?',
            options: $agentOptions,
            default: $config['default_agent_id']
        );

        info("Default agent: @{$selectedAgentId}");

        return $selectedAgentId;
    }
}
