<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Safe\symlink;

class LaraClawAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:agent
                            {action : Action to perform (list|add|remove|show|default|sync-skills)}
                            {agent_id? : Agent ID}
                            {--name= : Agent name}
                            {--provider= : Provider (anthropic/openai/ollama/etc)}
                            {--model= : Model name}
                            {--directory= : Working directory}
                            {--all : Apply to all agents (for sync-skills)}';

    /**
     * The console command description.
     */
    protected $description = 'Manage LaraClaw agents';

    protected SettingsService $settings;

    /**
     * Get the provider registry from config.
     * Only includes text-capable providers for agent use.
     *
     * @return array<string, array{display: string, models: array<string, string>, default_model: string}>
     */
    protected function getProviders(): array
    {
        $providers = config('laraclaw.providers', []);

        // Filter to only text-capable providers for agent use
        return array_filter($providers, fn ($p) => $p['supports_text'] ?? true);
    }

    /**
     * Execute the console command.
     */
    public function handle(SettingsService $settings): int
    {
        $this->settings = $settings;
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listAgents(),
            'add' => $this->addAgent(),
            'remove' => $this->removeAgent(),
            'show' => $this->showAgent(),
            'default' => $this->setDefaultAgent(),
            'sync-skills' => $this->syncSkills(),
            default => $this->showHelp(),
        };
    }

    /**
     * List all agents.
     */
    protected function listAgents(): int
    {
        $agents = $this->settings->getAgents();

        if ($agents->isEmpty()) {
            $this->info('No agents configured.');
            $this->info('Add an agent with: php artisan laraclaw:agent add <agent_id>');

            return Command::SUCCESS;
        }

        $this->info('Configured Agents:');
        $this->info('');

        foreach ($agents as $id => $agent) {
            $this->line(sprintf(
                '  <info>@%s</info> - %s [%s/%s]',
                $id,
                $agent['name'],
                $agent['provider'],
                $agent['model']
            ));
            $this->line(sprintf('    Directory: %s', $agent['working_directory'] ?? 'default'));
        }

        return Command::SUCCESS;
    }

    /**
     * Get the templates directory path.
     * Templates are stored in resources/laraclaw/template/
     */
    protected function getTemplatesDir(): string
    {
        return resource_path('laraclaw/template');
    }

    /**
     * Copy template files to an agent's working directory.
     */
    protected function copyAgentTemplates(string $agentDir, string $agentId): void
    {
        $templatesDir = $this->getTemplatesDir();

        // Copy AGENTS.md
        $agentsMdSource = $templatesDir.'/AGENTS.md';
        if (File::exists($agentsMdSource)) {
            File::copy($agentsMdSource, $agentDir.'/AGENTS.md');
            $this->line("  <fg=green>\u{2713} Copied AGENTS.md to {$agentDir}/</>");
        }

        // Copy .claude directory
        $claudeDirSource = $templatesDir.'/.claude';
        if (File::isDirectory($claudeDirSource) && File::allFiles($claudeDirSource)) {
            File::copyDirectory($claudeDirSource, $agentDir.'/.claude');
            $this->line("  <fg=green>\u{2713} Copied .claude/ to {$agentDir}/</>");
        } else {
            // Create empty .claude directory
            File::ensureDirectoryExists($agentDir.'/.claude');
        }

        // Copy heartbeat.md
        $heartbeatSource = $templatesDir.'/heartbeat.md';
        if (File::exists($heartbeatSource)) {
            File::copy($heartbeatSource, $agentDir.'/heartbeat.md');
            $this->line("  <fg=green>\u{2713} Copied heartbeat.md to {$agentDir}/</>");
        }

        // Copy SOUL.md to .laraclaw directory
        $soulSource = $templatesDir.'/SOUL.md';
        if (File::exists($soulSource)) {
            $laraclawDir = $agentDir.'/.laraclaw';
            if (! File::isDirectory($laraclawDir)) {
                File::makeDirectory($laraclawDir, 0755, true);
            }
            File::copy($soulSource, $laraclawDir.'/SOUL.md');
            $this->line("  <fg=green>\u{2713} Copied SOUL.md to {$laraclawDir}/</>");
        }

        // Also copy AGENTS.md to .claude/CLAUDE.md for Claude CLI
        if (File::exists($agentsMdSource)) {
            $claudeDir = $agentDir.'/.claude';
            if (! File::isDirectory($claudeDir)) {
                File::makeDirectory($claudeDir, 0755, true);
            }
            File::copy($agentsMdSource, $claudeDir.'/CLAUDE.md');
            $this->line("  <fg=green>\u{2713} Copied CLAUDE.md to {$claudeDir}/</>");
        }

        // Link .agents/skills directory if it exists
        $skillsSource = $templatesDir.'/.agents/skills';
        if (File::isDirectory($skillsSource)) {
            // Create .agents directory and symlink skills
            $agentAgentsDir = $agentDir.'/.agents';
            if (! File::isDirectory($agentAgentsDir)) {
                File::makeDirectory($agentAgentsDir, 0755, true);
            }

            $skillsTarget = $agentAgentsDir.'/skills';
            if (! File::exists($skillsTarget) && ! is_link($skillsTarget)) {
                symlink($skillsSource, $skillsTarget);
                $this->line("  <fg=green>\u{2713} Linked skills to {$skillsTarget}</>");
            }

            // Also link to .claude/skills
            $claudeSkillsTarget = $agentDir.'/.claude/skills';
            if (! File::exists($claudeSkillsTarget) && ! is_link($claudeSkillsTarget)) {
                symlink($skillsSource, $claudeSkillsTarget);
                $this->line("  <fg=green>\u{2713} Linked skills to {$claudeSkillsTarget}</>");
            }
        }
    }

    /**
     * Add a new agent.
     */
    protected function addAgent(): int
    {
        $agentId = $this->argument('agent_id');

        if (! $agentId) {
            $this->error('Please provide an agent ID.');
            $this->info('Usage: php artisan laraclaw:agent add <agent_id> [options]');

            return Command::FAILURE;
        }

        $agentId = strtolower($agentId);

        // Check if agent already exists
        $existingAgents = $this->settings->getAgents();
        if (isset($existingAgents[$agentId])) {
            $this->error("Agent '{$agentId}' already exists.");

            return Command::FAILURE;
        }

        // Get options or prompt for them
        $name = $this->option('name') ?? $this->ask('Agent name', ucfirst($agentId));

        // Provider selection with display names
        $providers = $this->getProviders();
        $providerChoices = [];
        foreach ($providers as $pid => $p) {
            $providerChoices[] = $p['display'];
        }
        $providerChoice = $this->option('provider') ?? $this->choice('Provider', $providerChoices, 0);

        // Find provider ID from choice (handle both ID and display name)
        $providerId = null;
        if (isset($providers[$providerChoice])) {
            $providerId = $providerChoice;
        } else {
            $providerId = array_keys($providers)[$this->getChoiceIndex($providerChoice, $providerChoices)];
        }
        $provider = $providers[$providerId];

        // Model selection
        $modelChoices = array_values($provider['models']);
        $defaultModelIndex = array_search($provider['default_model'], array_keys($provider['models'])) ?: 0;
        $modelChoice = $this->option('model') ?? $this->choice('Model', $modelChoices, $defaultModelIndex);

        // Find model ID from choice
        $modelId = null;
        if (isset($provider['models'][$modelChoice])) {
            $modelId = $modelChoice;
        } else {
            $modelId = array_keys($provider['models'])[$this->getChoiceIndex($modelChoice, $modelChoices)];
        }

        $workspacePath = $this->settings->getWorkspacePath();
        $defaultDir = $workspacePath.'/'.$agentId;
        $directory = $this->option('directory') ?? $this->ask('Working directory', $defaultDir);

        // Create agent directory
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
            $this->line("  <fg=green>\u{2713} Created agent directory: {$directory}</>");
        }

        // Copy templates to agent directory
        $this->line("  <info>Copying templates to @{$agentId}...</info>");
        $this->copyAgentTemplates($directory, $agentId);

        // Save to settings
        $this->settings->setAgent($agentId, [
            'name' => $name,
            'provider' => $providerId,
            'model' => $modelId,
            'working_directory' => $directory,
        ]);

        $this->info('');
        $this->info("Agent '@{$agentId}' created successfully.");
        $this->info("  Name: {$name}");
        $this->info("  Provider: {$providerId}");
        $this->info("  Model: {$modelId}");
        $this->info("  Directory: {$directory}");

        return Command::SUCCESS;
    }

    /**
     * Get the index of a choice from the choices array.
     *
     * @param  array<int, string>  $choices
     */
    protected function getChoiceIndex(string $choice, array $choices): int
    {
        return array_search($choice, $choices) ?: 0;
    }

    /**
     * Remove an agent.
     */
    protected function removeAgent(): int
    {
        $agentId = $this->argument('agent_id');

        if (! $agentId) {
            $this->error('Please provide an agent ID.');

            return Command::FAILURE;
        }

        $agentId = strtolower($agentId);
        $agents = $this->settings->getAgents();

        if (! isset($agents[$agentId])) {
            $this->error("Agent '{$agentId}' not found.");

            return Command::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to remove agent '@{$agentId}'?")) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->settings->removeAgent($agentId);
        $this->info("Agent '@{$agentId}' removed.");

        return Command::SUCCESS;
    }

    /**
     * Show agent details.
     */
    protected function showAgent(): int
    {
        $agentId = $this->argument('agent_id');

        if (! $agentId) {
            $this->error('Please provide an agent ID.');

            return Command::FAILURE;
        }

        $agentId = strtolower($agentId);
        $agent = $this->settings->getAgent($agentId);

        if (! $agent) {
            $this->error("Agent '{$agentId}' not found.");

            return Command::FAILURE;
        }

        $this->info("Agent: @{$agentId}");
        $this->info('');
        $this->info("  Name: {$agent['name']}");
        $this->info("  Provider: {$agent['provider']}");
        $this->info("  Model: {$agent['model']}");
        $this->info("  Directory: {$agent['working_directory']}");

        if (! empty($agent['system_prompt'])) {
            $this->info('  System Prompt: '.Str::limit($agent['system_prompt'], 50));
        }

        if (! empty($agent['prompt_file'])) {
            $this->info("  Prompt File: {$agent['prompt_file']}");
        }

        // Show teams this agent belongs to
        $teams = $this->settings->getTeams();
        $agentTeams = [];
        foreach ($teams as $teamId => $team) {
            if (in_array($agentId, $team['agents'] ?? [])) {
                $isLeader = ($team['leader_agent'] ?? null) === $agentId;
                $agentTeams[] = "@{$teamId}".($isLeader ? ' (leader)' : '');
            }
        }

        if (! empty($agentTeams)) {
            $this->info('');
            $this->info('  Teams:');
            foreach ($agentTeams as $team) {
                $this->info("    - {$team}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Show help information.
     */
    protected function showHelp(): int
    {
        $this->info('LaraClaw Agent Management');
        $this->info('');
        $this->info('Usage:');
        $this->info('  php artisan laraclaw:agent list                    - List all agents');
        $this->info('  php artisan laraclaw:agent add <id> [options]      - Add a new agent');
        $this->info('  php artisan laraclaw:agent remove <id>             - Remove an agent');
        $this->info('  php artisan laraclaw:agent show <id>               - Show agent details');
        $this->info('  php artisan laraclaw:agent default [id]            - Set default agent');
        $this->info('  php artisan laraclaw:agent sync-skills [id]        - Re-scan and update agent skills');
        $this->info('');
        $this->info('Options for add:');
        $this->info('  --name=       Agent name');
        $this->info('  --provider=   Provider (anthropic/openai/ollama/mistral/groq/xai/gemini/deepseek/openrouter)');
        $this->info('  --model=      Model name');
        $this->info('  --directory=  Working directory');
        $this->info('');
        $this->info('Options for sync-skills:');
        $this->info('  --all         Update skills for all agents');
        $this->info('');
        $this->info('Available providers:');
        foreach ($this->getProviders() as $pid => $p) {
            $this->info("  {$pid} - {$p['display']}");
        }

        return Command::SUCCESS;
    }

    /**
     * Sync skills for agents by re-scanning available skills.
     */
    protected function syncSkills(): int
    {
        $skillService = app(\App\Services\SkillSearchService::class);
        $agentId = $this->argument('agent_id');
        $allAgents = $this->option('all');

        // Refresh the skill index first
        $this->info('Scanning available skills...');
        $skills = $skillService->refreshIndex();
        $skillNames = array_keys($skills->toArray());
        $this->info(sprintf('  Found %d skills: %s', count($skillNames), implode(', ', $skillNames)));

        if ($allAgents) {
            // Update all agents
            $agents = \App\Models\Agent::all();
            if ($agents->isEmpty()) {
                $this->info('No agents found in database.');

                return Command::SUCCESS;
            }

            $this->info('');
            $this->info('Updating skills for all agents...');

            foreach ($agents as $agent) {
                $capabilities = $this->inferCapabilities($agent);
                $agent->skills = $skillNames;
                $agent->capabilities = $capabilities;
                $agent->save();
                $this->line(sprintf('  <info>✓</info> @%s: %d skills, capabilities: %s',
                    $agent->agent_id,
                    count($skillNames),
                    implode(', ', $capabilities)
                ));
            }

            $this->info('');
            $this->info(sprintf('Updated %d agent(s).', $agents->count()));

            return Command::SUCCESS;
        }

        // Update single agent
        if (! $agentId) {
            $this->error('Please provide an agent ID or use --all to update all agents.');
            $this->info('Usage: php artisan laraclaw:agent sync-skills <agent_id>');
            $this->info('       php artisan laraclaw:agent sync-skills --all');

            return Command::FAILURE;
        }

        $agentId = strtolower($agentId);
        $agent = \App\Models\Agent::where('agent_id', $agentId)->first();

        if (! $agent) {
            $this->error("Agent '@{$agentId}' not found in database.");

            return Command::FAILURE;
        }

        $capabilities = $this->inferCapabilities($agent);
        $agent->skills = $skillNames;
        $agent->capabilities = $capabilities;
        $agent->save();

        $this->info('');
        $this->info("Updated agent '@{$agentId}':");
        $this->info(sprintf('  Skills: %s', implode(', ', $skillNames)));
        $this->info(sprintf('  Capabilities: %s', implode(', ', $capabilities)));

        return Command::SUCCESS;
    }

    /**
     * Infer capabilities from agent name/ID.
     *
     * @return array<int, string>
     */
    protected function inferCapabilities(\App\Models\Agent $agent): array
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

    /**
     * Set the default agent for routing.
     */
    protected function setDefaultAgent(): int
    {
        $agents = $this->settings->getAgents();
        if ($agents->isEmpty()) {
            $this->error('No agents configured.');
            $this->info('Add an agent first with: php artisan laraclaw:agent add <agent_id>');

            return Command::FAILURE;
        }

        $currentDefault = $this->settings->getDefaultAgentId();
        $agentId = $this->argument('agent_id');

        // If agent ID provided directly
        if ($agentId) {
            $agentId = strtolower($agentId);

            if (! isset($agents[$agentId])) {
                $this->error("Agent '{$agentId}' not found.");

                return Command::FAILURE;
            }

            $this->settings->set('agents.default_agent_id', $agentId);
            $this->info("Default agent set to '@{$agentId}' ({$agents[$agentId]['name']}).");

            return Command::SUCCESS;
        }

        // Interactive selection
        $this->info('Select the default agent for message routing:');
        $this->info('(Messages without @mention will be routed to this agent)');
        $this->info('');

        $choices = [];
        foreach ($agents as $id => $agent) {
            $marker = ($id === $currentDefault) ? ' (current)' : '';
            $choices[] = "@{$id} - {$agent['name']} [{$agent['provider']}/{$agent['model']}]{$marker}";
        }

        $choice = $this->choice('Which agent should be the default?', $choices, 0);
        $selectedIndex = $this->getChoiceIndex($choice, $choices);
        $selectedAgentId = $agents->keys()[$selectedIndex];

        $this->settings->set('agents.default_agent_id', $selectedAgentId);
        $this->info('');
        $this->info("Default agent set to '@{$selectedAgentId}' ({$agents[$selectedAgentId]['name']}).");

        return Command::SUCCESS;
    }
}
