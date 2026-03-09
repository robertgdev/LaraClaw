<?php

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;

class LaraClawTeamCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:team
                            {action? : Action to perform (list|add|remove|show)}
                            {team_id? : Team ID}
                            {--name= : Team name}
                            {--agents= : Comma-separated list of agent IDs}
                            {--leader= : Leader agent ID}';

    /**
     * The console command description.
     */
    protected $description = 'Manage LaraClaw teams';

    protected SettingsService $settings;

    /**
     * Available actions for the team command.
     *
     * @var array<string, string>
     */
    protected array $actions = [
        'list' => 'List all teams',
        'add' => 'Add a new team',
        'remove' => 'Remove a team',
        'show' => 'Show team details',
    ];

    /**
     * Execute the console command.
     */
    public function handle(SettingsService $settings): int
    {
        $this->settings = $settings;
        $action = $this->argument('action');

        // If no action provided, run interactively
        if (! $action) {
            return $this->runInteractive();
        }

        // Validate action
        if (! isset($this->actions[$action])) {
            $this->error("Invalid action '{$action}'. Valid actions: ".implode(', ', array_keys($this->actions)));

            return Command::FAILURE;
        }

        return match ($action) {
            'list' => $this->listTeams(),
            'add' => $this->addTeam(),
            'remove' => $this->removeTeam(),
            'show' => $this->showTeam(),
            default => $this->showHelp(),
        };
    }

    /**
     * Run in interactive mode.
     */
    protected function runInteractive(): int
    {
        $this->info('LaraClaw Team Management');
        $this->newLine();

        // Show current teams status
        $teams = $this->settings->getTeams();
        $teamCount = count($teams);
        $this->line("Teams configured: <info>{$teamCount}</info>");
        $this->newLine();

        // Ask what action to perform
        $actionChoices = array_values($this->actions);
        $selectedAction = $this->choice('What would you like to do?', $actionChoices);

        // Map back to action key
        $actionKey = array_search($selectedAction, $this->actions);

        return match ($actionKey) {
            'list' => $this->listTeams(),
            'add' => $this->addTeamInteractive(),
            'remove' => $this->removeTeamInteractive(),
            'show' => $this->showTeamInteractive(),
            default => Command::FAILURE,
        };
    }

    /**
     * List all teams.
     */
    protected function listTeams(): int
    {
        $teams = $this->settings->getTeams();
        if ($teams->isEmpty()) {
            $this->info('No teams configured.');
            $this->info('Add a team with: php artisan laraclaw:team add <team_id>');

            return Command::SUCCESS;
        }

        $this->info('Configured Teams:');
        $this->newLine();

        foreach ($teams as $id => $team) {
            $this->line(sprintf(
                '  <info>@%s</info> - %s',
                $id,
                $team['name']
            ));
            $this->line(sprintf('    Agents: %s', implode(', ', array_map(fn ($a) => "@{$a}", $team['agents']))));
            $this->line(sprintf('    Leader: @%s', $team['leader_agent']));
            $this->newLine();
        }

        return Command::SUCCESS;
    }

    /**
     * Add a new team (argument-driven mode).
     */
    protected function addTeam(): int
    {
        $teamId = $this->argument('team_id');

        if (! $teamId) {
            $this->error('Please provide a team ID.');
            $this->info('Usage: php artisan laraclaw:team add <team_id> [options]');

            return Command::FAILURE;
        }

        return $this->createTeam($teamId);
    }

    /**
     * Add a new team (interactive mode).
     */
    protected function addTeamInteractive(): int
    {
        $this->info('Add a New Team');
        $this->newLine();

        // Get existing teams to check for duplicates
        $existingTeams = $this->settings->getTeams();

        // Ask for team ID
        $teamId = $this->ask('Team ID (e.g., "dev-team")');
        if (empty($teamId)) {
            $this->error('Team ID is required.');

            return Command::FAILURE;
        }

        return $this->createTeam($teamId);
    }

    /**
     * Create a team with the given ID.
     */
    protected function createTeam(string $teamId): int
    {
        $teamId = strtolower($teamId);

        // Check if team already exists
        $existingTeams = $this->settings->getTeams();
        if (isset($existingTeams[$teamId])) {
            $this->error("Team '{$teamId}' already exists.");

            return Command::FAILURE;
        }

        // Get available agents
        $agents = $this->settings->getAgents();
        if ($agents->isEmpty()) {
            $this->error('No agents available. Please add agents first.');

            return Command::FAILURE;
        }
        $agentIds = $agents->keys();

        // Get name
        $name = $this->option('name');
        if (! $name) {
            $name = $this->ask('Team name', ucfirst($teamId));
        }

        // Get agents
        $selectedAgents = $this->option('agents');
        if ($selectedAgents) {
            $selectedAgents = array_map('trim', explode(',', $selectedAgents));
        } else {
            // Interactive selection with checkboxes
            $this->newLine();
            $this->info('Select agents for the team (use space to select, enter to confirm):');

            // Build choices with agent info
            $agentChoices = [];
            foreach ($agents as $id => $agent) {
                $agentChoices[$id] = "@{$id} - {$agent['name']} [{$agent['provider']}/{$agent['model']}]";
            }

            $selectedAgents = $this->selectAgentsWithCheckboxes($agentChoices);

            if (empty($selectedAgents)) {
                $this->error('No agents selected.');

                return Command::FAILURE;
            }
        }

        // Validate agents
        $invalidAgents = $agentIds->diff($selectedAgents);
        if ($invalidAgents->isNotEmpty()) {
            $this->error('Invalid agents: '.$invalidAgents->implode(', '));

            return Command::FAILURE;
        }

        if (count($selectedAgents) < 2) {
            $this->error('A team must have at least 2 agents.');

            return Command::FAILURE;
        }

        // Get leader
        $leader = $this->option('leader');
        if (! $leader) {
            $this->newLine();
            $this->info('Select the team leader:');

            // Build leader choices
            $leaderChoices = [];
            foreach ($selectedAgents as $agentId) {
                $agent = $agents[$agentId];
                $leaderChoices[$agentId] = "@{$agentId} - {$agent['name']}";
            }

            $leaderChoice = $this->choice('Team leader', array_values($leaderChoices), 0);
            $leader = array_search($leaderChoice, $leaderChoices);
        }

        if (! in_array($leader, $selectedAgents)) {
            $this->error('Leader must be one of the team agents.');

            return Command::FAILURE;
        }

        // Save to settings
        $this->settings->setTeam($teamId, [
            'name' => $name,
            'agents' => $selectedAgents,
            'leader_agent' => $leader,
        ]);

        $this->newLine();
        $this->info("Team '@{$teamId}' created successfully.");
        $this->info("  Name: {$name}");
        $this->info('  Agents: '.implode(', ', array_map(fn ($a) => "@{$a}", $selectedAgents)));
        $this->info("  Leader: @{$leader}");

        return Command::SUCCESS;
    }

    /**
     * Select agents using a numbered list interface.
     * User enters comma-separated numbers which are mapped to agent IDs.
     *
     * @param  array<string, string>  $agentChoices
     * @return array<int, string>
     */
    protected function selectAgentsWithCheckboxes(array $agentChoices): array
    {
        $agentIds = array_keys($agentChoices);
        $agentList = array_values($agentChoices);

        // Display all agents with numbered indices
        $this->newLine();
        $this->line('<comment>Available agents:</comment>');
        foreach ($agentList as $index => $label) {
            $num = $index + 1;
            $this->line("  <info>[{$num}]</info> {$label}");
        }
        $this->newLine();
        $this->line('<comment>Enter numbers separated by commas (e.g., 1,2,3):</comment>');

        // Use a simple ask and parse the result
        $input = $this->ask('Agents to add');

        if (empty($input)) {
            return [];
        }

        // Parse the input - expect numbers
        $numbers = array_map('trim', explode(',', $input));
        $selected = [];

        foreach ($numbers as $num) {
            // Convert to integer and adjust for 0-based index
            $index = (int) $num - 1;
            if ($index >= 0 && $index < count($agentIds)) {
                $selected[] = $agentIds[$index];
            }
        }

        // Remove duplicates and return
        return array_unique($selected);
    }

    /**
     * Remove a team (argument-driven mode).
     */
    protected function removeTeam(): int
    {
        $teamId = $this->argument('team_id');

        if (! $teamId) {
            $this->error('Please provide a team ID.');

            return Command::FAILURE;
        }

        return $this->deleteTeam($teamId);
    }

    /**
     * Remove a team (interactive mode).
     */
    protected function removeTeamInteractive(): int
    {
        $teams = $this->settings->getTeams();
        if ($teams->isEmpty()) {
            $this->info('No teams configured.');

            return Command::SUCCESS;
        }

        $this->info('Remove a Team');
        $this->newLine();

        // Build choices
        $choices = [];
        foreach ($teams as $id => $team) {
            $choices[$id] = "@{$id} - {$team['name']}";
        }

        $selected = $this->choice('Select team to remove', array_values($choices));
        $teamId = array_search($selected, $choices);

        return $this->deleteTeam($teamId);
    }

    /**
     * Delete a team with the given ID.
     */
    protected function deleteTeam(string $teamId): int
    {
        $teamId = strtolower($teamId);
        $teams = $this->settings->getTeams();

        if (! isset($teams[$teamId])) {
            $this->error("Team '{$teamId}' not found.");

            return Command::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to remove team '@{$teamId}'?")) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->settings->removeTeam($teamId);
        $this->info("Team '@{$teamId}' removed.");

        return Command::SUCCESS;
    }

    /**
     * Show team details (argument-driven mode).
     */
    protected function showTeam(): int
    {
        $teamId = $this->argument('team_id');

        if (! $teamId) {
            $this->error('Please provide a team ID.');

            return Command::FAILURE;
        }

        return $this->displayTeam($teamId);
    }

    /**
     * Show team details (interactive mode).
     */
    protected function showTeamInteractive(): int
    {
        $teams = $this->settings->getTeams();
        if ($teams->isEmpty()) {
            $this->info('No teams configured.');

            return Command::SUCCESS;
        }

        $this->info('Show Team Details');
        $this->newLine();

        // Build choices
        $choices = [];
        foreach ($teams as $id => $team) {
            $choices[$id] = "@{$id} - {$team['name']}";
        }

        $selected = $this->choice('Select team to show', array_values($choices));
        $teamId = array_search($selected, $choices);

        return $this->displayTeam($teamId);
    }

    /**
     * Display team details.
     */
    protected function displayTeam(string $teamId): int
    {
        $teamId = strtolower($teamId);
        $team = $this->settings->getTeam($teamId);

        if (! $team) {
            $this->error("Team '{$teamId}' not found.");

            return Command::FAILURE;
        }

        $this->info("Team: @{$teamId}");
        $this->newLine();
        $this->info("  Name: {$team['name']}");
        $this->info("  Leader: @{$team['leader_agent']}");
        $this->newLine();
        $this->info('  Agents:');

        $agents = $this->settings->getAgents();
        foreach ($team['agents'] as $agentId) {
            $agent = $agents[$agentId] ?? null;
            $isLeader = $agentId === $team['leader_agent'] ? ' <comment>(leader)</comment>' : '';
            if ($agent) {
                $this->line("    - <info>@{$agentId}</info> - {$agent['name']} [{$agent['provider']}/{$agent['model']}]{$isLeader}");
            } else {
                $this->line("    - <error>@{$agentId}</error> - (not found){$isLeader}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Show help information.
     */
    protected function showHelp(): int
    {
        $this->info('LaraClaw Team Management');
        $this->newLine();
        $this->info('Usage:');
        $this->info('  php artisan laraclaw:team                    - Interactive mode');
        $this->info('  php artisan laraclaw:team list               - List all teams');
        $this->info('  php artisan laraclaw:team add <id> [options] - Add a new team');
        $this->info('  php artisan laraclaw:team remove <id>        - Remove a team');
        $this->info('  php artisan laraclaw:team show <id>          - Show team details');
        $this->newLine();
        $this->info('Options for add:');
        $this->info('  --name=       Team name');
        $this->info('  --agents=     Comma-separated list of agent IDs');
        $this->info('  --leader=     Leader agent ID');

        return Command::SUCCESS;
    }
}
