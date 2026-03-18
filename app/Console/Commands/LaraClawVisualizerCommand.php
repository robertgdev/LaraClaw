<?php

namespace App\Console\Commands;

use App\Models\ConversationMessage;
use App\Models\Event;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use function Safe\shell_exec;

class LaraClawVisualizerCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'laraclaw:visualizer
                            {--team= : Filter to a specific team ID}
                            {--refresh=1 : Refresh interval in seconds}';

    /**
     * The console command description.
     */
    protected $description = 'Real-time TUI for watching team conversations';

    protected int $startTime;

    /**
     * @var array<string, array{id: string, name: string, provider: string, model: string, status: string, lastActivity: string, responseLength: int}>
     */
    protected array $agentStates = [];

    /**
     * @var array<int, array{time: string, icon: string, text: string, color: string}>
     */
    protected array $logEntries = [];

    protected int $totalProcessed = 0;

    protected int $lastEventId = 0;

    protected SettingsService $settings;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->settings = app(SettingsService::class);
        $this->startTime = time();
        $teamId = $this->option('team');
        $refresh = (int) $this->option('refresh');

        // Initialize agent states
        $this->initializeAgentStates($teamId);

        // Clear screen
        $this->clearScreen();

        // Main loop - runs until user interrupts with Ctrl+C
        /** @phpstan-ignore-next-line */
        while (true) {
            $this->render($teamId);
            sleep($refresh);
            $this->processNewEvents($teamId);
        }
    }

    /**
     * Initialize agent states from settings.
     */
    protected function initializeAgentStates(?string $teamId): void
    {
        $agents = $this->settings->getAgents();
        $teams = $this->settings->getTeams();

        // Determine which agents to show
        // FIXME: convert to collection
        $agentIds = [];
        if ($teamId) {
            if (isset($teams[$teamId])) {
                $agentIds = $teams[$teamId]['agents'];
            }
        } else {
            // Show all agents that belong to any team
            foreach ($teams as $team) {
                $agentIds = array_merge($agentIds, $team['agents']);
            }
            $agentIds = array_unique($agentIds);
        }

        // If no team agents, show all agents
        if ($agents->isEmpty()) {
            $agentIds = $agents->keys();
        }

        foreach ($agentIds as $id) {
            if (isset($agents[$id])) {
                $agent = $agents[$id];
                $this->agentStates[$id] = [
                    'id' => $id,
                    'name' => $agent['name'],
                    'provider' => $agent['provider'],
                    'model' => $agent['model'],
                    'status' => 'idle',
                    'lastActivity' => '',
                    'responseLength' => 0,
                ];
            }
        }
    }

    /**
     * Process new events from database.
     */
    protected function processNewEvents(?string $teamId): void
    {
        $query = Event::query()
            ->where('id', '>', $this->lastEventId)
            ->orderBy('id');

        $events = $query->get();

        foreach ($events as $event) {
            $this->lastEventId = $event->id;
            $this->handleEvent($event, $teamId);
        }
    }

    /**
     * Handle a single event.
     */
    protected function handleEvent(Event $event, ?string $teamId): void
    {
        $data = $event->data;
        $time = $event->occurred_at->format('H:i:s');

        switch ($event->type) {
            case 'processor_start':
                $this->addLog('⚡', 'Queue processor started', 'green');
                break;

            case 'message_received':
                $channel = $data['channel'] ?? 'unknown';
                $sender = $data['sender'] ?? 'unknown';
                $message = $this->truncate($data['message'] ?? '', 50);
                $this->addLog('✉', "[{$channel}] {$sender}: {$message}", 'white');
                break;

            case 'agent_routed':
                $aid = $data['agentId'] ?? '';
                if (isset($this->agentStates[$aid])) {
                    $this->agentStates[$aid]['status'] = 'active';
                    $this->agentStates[$aid]['lastActivity'] = 'Routing...';
                }
                $isTeam = $data['isTeamRouted'] ?? false;
                $this->addLog('→', "Routed to @{$aid}".($isTeam ? ' (via team)' : ''), 'cyan');
                break;

            case 'team_chain_start':
                $teamName = $data['teamName'] ?? 'Unknown';
                $agents = $data['agents'] ?? [];
                $agentList = implode(', ', array_map(fn ($a) => '@'.$a, $agents));
                $this->addLog('⛓', "Conversation started: {$teamName} [{$agentList}]", 'magenta');
                break;

            case 'chain_step_start':
                $aid = $data['agentId'] ?? '';
                $from = $data['fromAgent'] ?? null;
                if (isset($this->agentStates[$aid])) {
                    $this->agentStates[$aid]['status'] = 'active';
                    $this->agentStates[$aid]['lastActivity'] = $from ? "From @{$from}" : 'Processing';
                }
                break;

            case 'chain_step_done':
                $aid = $data['agentId'] ?? '';
                $length = $data['responseLength'] ?? 0;
                if (isset($this->agentStates[$aid])) {
                    $this->agentStates[$aid]['status'] = 'done';
                    $this->agentStates[$aid]['responseLength'] = $length;
                }
                $text = $this->truncate($data['responseText'] ?? "({$length} chars)", 60);
                $this->addLog('💬', "@{$aid}: {$text}", 'white');
                break;

            case 'chain_handoff':
                $from = $data['fromAgent'] ?? '';
                $to = $data['toAgent'] ?? '';
                if (isset($this->agentStates[$to])) {
                    $this->agentStates[$to]['status'] = 'waiting';
                    $this->agentStates[$to]['lastActivity'] = "Handoff from @{$from}";
                }
                $this->addLog('→', "@{$from} → @{$to}", 'yellow');
                break;

            case 'team_chain_end':
                $agents = $data['agents'] ?? [];
                $agentList = implode(', ', array_map(fn ($a) => '@'.$a, $agents));
                $this->addLog('✔', "Conversation complete [{$agentList}]", 'green');
                foreach ($agents as $aid) {
                    if (isset($this->agentStates[$aid])) {
                        $this->agentStates[$aid]['status'] = 'done';
                    }
                }
                break;

            case 'response_ready':
                $this->totalProcessed++;
                // Reset agent states after a delay
                foreach ($this->agentStates as $id => $state) {
                    if (in_array($state['status'], ['done', 'error'])) {
                        $this->agentStates[$id]['status'] = 'idle';
                        $this->agentStates[$id]['lastActivity'] = 'Just now';
                    }
                }
                break;
        }
    }

    /**
     * Add a log entry.
     */
    protected function addLog(string $icon, string $text, string $color): void
    {
        $time = date('H:i:s');
        $this->logEntries[] = [
            'time' => $time,
            'icon' => $icon,
            'text' => $text,
            'color' => $color,
        ];

        // Keep only last 50 entries
        if (count($this->logEntries) > 50) {
            $this->logEntries = array_slice($this->logEntries, -50);
        }
    }

    /**
     * Render the visualizer.
     */
    protected function render(?string $teamId): void
    {
        $this->clearScreen();

        // Header
        $this->renderHeader($teamId);

        // Agent cards
        $this->renderAgentCards($teamId);

        // Teams list
        if (! $teamId) {
            $this->renderTeamsList();
        }

        // Activity log
        $this->renderActivityLog();

        // Status bar
        $this->renderStatusBar();

        // Instructions
        $this->line('');
        $this->line('<fg=gray>Press Ctrl+C to quit</>');
    }

    /**
     * Render the header.
     */
    protected function renderHeader(?string $teamId): void
    {
        $uptime = $this->timeAgo($this->startTime);
        $teamName = null;
        $teams = $this->settings->getTeams();

        if ($teamId && isset($teams[$teamId])) {
            $teamName = $teams[$teamId]['name'];
        }

        $this->line('<fg=magenta>★</> <fg=white;options=bold>LaraClaw Team Visualizer</> <fg=gray>│</> '.
            ($teamId ? "<fg=cyan;options=bold>@{$teamId}</> <fg=gray>({$teamName})</>" : '<fg=yellow>all teams</>').
            " <fg=gray>│</> <fg=gray>up {$uptime}</>");
        $this->line('<fg=gray>'.str_repeat('─', 72).'</>');
    }

    /**
     * Render agent cards.
     */
    protected function renderAgentCards(?string $teamId): void
    {
        if (empty($this->agentStates)) {
            $this->line('<fg=yellow>No agents configured.</>');
            $this->line('<fg=gray>Create a team with: php artisan laraclaw:team add</>');

            return;
        }

        $leaderAgent = null;
        $teams = $this->settings->getTeams();
        if ($teamId && isset($teams[$teamId])) {
            $leaderAgent = $teams[$teamId]['leader_agent'];
        }

        foreach ($this->agentStates as $agent) {
            $status = $agent['status'];
            $icon = $this->getStatusIcon($status);
            $color = $this->getStatusColor($status);
            $leader = $agent['id'] === $leaderAgent ? ' <fg=yellow>★</>' : '';

            $this->line("<{$color}>{$icon}</> <fg=white;options=bold>@{$agent['id']}</>{$leader} <fg=gray>{$agent['name']}</>");
            $this->line("  <fg=gray>{$agent['provider']}/{$agent['model']}</>");

            if ($status === 'active') {
                $this->line('  <fg=cyan>▸ Processing...</>');
            } elseif ($status === 'done') {
                $this->line("  <fg=green>✓ Done ({$agent['responseLength']} chars)</>");
            } elseif ($status === 'error') {
                $this->line('  <fg=red>✗ Error</>');
            } elseif ($status === 'waiting') {
                $this->line("  <fg=yellow>◔ {$agent['lastActivity']}</>");
            } else {
                $this->line("  <fg=gray>{$agent['lastActivity']}</>");
            }
            $this->line('');
        }
    }

    /**
     * Render teams list.
     */
    protected function renderTeamsList(): void
    {
        $teams = $this->settings->getTeams();
        if ($teams->isEmpty()) {
            return;
        }

        $this->line('<fg=white;options=bold>≣ Teams</>');
        foreach ($teams as $id => $team) {
            $agents = implode(', ', array_map(fn ($a) => '@'.$a, $team['agents']));
            $this->line("  <fg=cyan;options=bold>@{$id}</> <fg=gray>{$team['name']}</> <fg=gray>[{$agents}]</> <fg=yellow>★</><fg=gray>@{$team['leader_agent']}</>");
        }
        $this->line('');
    }

    /**
     * Render activity log.
     */
    protected function renderActivityLog(): void
    {
        $this->line('<fg=white;options=bold>☰ Activity</>');
        $this->line('<fg=gray>'.str_repeat('─', 72).'</>');

        if (empty($this->logEntries)) {
            $this->line('<fg=gray;options=bold>  Waiting for events... (send a message to a team)</>');

            return;
        }

        $visible = array_slice($this->logEntries, -12);
        foreach ($visible as $entry) {
            $color = $entry['color'];
            $this->line("<fg=gray>{$entry['time']}</> {$entry['icon']} <fg={$color}>{$entry['text']}</>");
        }
    }

    /**
     * Render status bar.
     */
    protected function renderStatusBar(): void
    {
        $queueDepth = ConversationMessage::incoming()->pending()->count();
        $processorAlive = $this->isProcessorAlive();

        $this->line('<fg=gray>'.str_repeat('─', 72).'</>');
        $status = $processorAlive
            ? '<fg=green>●</> <fg=green>Queue Processor Online</>'
            : '<fg=yellow>○</> <fg=yellow>Queue Processor Idle</>';
        $queueColor = $queueDepth > 0 ? 'yellow' : 'green';

        $this->line("{$status} <fg=gray>│</> Queue: <fg={$queueColor}>{$queueDepth}</> <fg=gray>│</> Processed: <fg=cyan>{$this->totalProcessed}</>");
    }

    /**
     * Check if queue processor is alive.
     *
     * With Laravel Horizon/queues, we check if the Horizon process
     * or queue worker is actually running, not just recent activity.
     */
    protected function isProcessorAlive(): bool
    {
        // Check if Horizon is running via its status command
        $horizonStatus = trim(shell_exec('php artisan horizon:status 2>/dev/null') ?? '');
        if (str_contains($horizonStatus, 'running')) {
            return true;
        }

        // Fallback: check if any queue worker process is running
        // This works for both Horizon and standard queue:work
        $processCheck = shell_exec('pgrep -f "php artisan horizon|php artisan queue:work" 2>/dev/null');

        return ! empty($processCheck);
    }

    /**
     * Clear the terminal screen.
     */
    protected function clearScreen(): void
    {
        $this->output->write("\033[2J\033[H");
    }

    /**
     * Get status icon.
     */
    protected function getStatusIcon(string $status): string
    {
        return match ($status) {
            'idle' => '○',
            'active' => '●',
            'done' => '✓',
            'error' => '✗',
            'waiting' => '◔',
            default => '○',
        };
    }

    /**
     * Get status color.
     */
    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'idle' => 'gray',
            'active' => 'cyan',
            'done' => 'green',
            'error' => 'red',
            'waiting' => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Truncate a string.
     */
    protected function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max - 1).'…';
    }

    /**
     * Get time ago string.
     */
    protected function timeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return "{$diff}s ago";
        }
        if ($diff < 3600) {
            $m = floor($diff / 60);

            return "{$m}m ago";
        }
        $h = floor($diff / 3600);

        return "{$h}h ago";
    }
}
