<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Agent;
use App\Services\SettingsService;
use Illuminate\Console\OutputStyle;

/**
 * Renders CLI shell UI elements for the interactive chat.
 *
 * Handles welcome banners, help text, agent/team listings,
 * session displays, history output, and response formatting.
 */
class ChatShellRenderer
{
    public function __construct(
        protected SettingsService $settings,
    ) {}

    /**
     * Display the welcome banner.
     */
    public function displayWelcome(OutputStyle $output, string $defaultAgentId, ?string $defaultTeamId): void
    {
        $output->newLine();
        $output->writeln('<fg=cyan>╔══════════════════════════════════════════════════════════════════════╗</>');
        $output->writeln('<fg=cyan>║</> <fg=white;options=bold>  LaraClaw Interactive Shell 🦞</>                                      <fg=cyan>║</>');
        $output->writeln('<fg=cyan>╠══════════════════════════════════════════════════════════════════════╣</>');

        // Show default agent
        $agents = $this->settings->getAgents();
        $defaultAgent = $agents[$defaultAgentId] ?? null;
        if ($defaultAgent) {
            $output->writeln("<fg=cyan>║</> <fg=gray>  Default agent:</> <fg=green>@{$defaultAgentId}</> ({$defaultAgent->name})");
        }

        // Show team if set
        if ($defaultTeamId) {
            $teams = $this->settings->getTeams();
            $teamName = $teams[$defaultTeamId]->name ?? $defaultTeamId;
            $output->writeln("<fg=cyan>║</> <fg=gray>  Default team:</>  <fg=magenta>@{$defaultTeamId}</> ({$teamName})");
        }

        $output->writeln('<fg=cyan>║</>                                                                      <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=gray>  Commands:</>                                                          <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /help</>        <fg=gray>Show this help message</>                                <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /agent [id]</>  <fg=gray>Show or set default agent</>                             <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /team [id]</>   <fg=gray>Show or set default team</>                              <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /agents</>      <fg=gray>List all available agents</>                             <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /teams</>       <fg=gray>List all available teams</>                              <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /session</>     <fg=gray>Show current session info</>                             <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /sessions</>    <fg=gray>List all sessions</>                                     <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /new</>         <fg=gray>Start a new session</>                                   <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /reset</>       <fg=gray>Reset conversation context</>                            <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /clear</>       <fg=gray>Clear the screen</>                                      <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /history</>     <fg=gray>Show command history</>                                  <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=white>  /exit</>        <fg=gray>Exit the shell (or press Ctrl+D)</>                      <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</>                                                                      <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=gray>  Type a message to send to the default agent.</>                       <fg=cyan>║</>');
        $output->writeln('<fg=cyan>║</> <fg=gray>  Prefix with </><fg=white>@agent_id</><fg=gray> to route to a specific agent.</>                <fg=cyan>║</>');
        $output->writeln('<fg=cyan>╚══════════════════════════════════════════════════════════════════════╝</>');
        $output->newLine();
    }

    /**
     * Display the help message.
     */
    public function showHelp(OutputStyle $output): void
    {
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('<fg=white;options=bold>  Available Commands</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('  <fg=white>/help</>        Show this help message');
        $output->writeln('  <fg=white>/agent [id]</>  Show or set default agent');
        $output->writeln('  <fg=white>/team [id]</>   Show or set default team');
        $output->writeln('  <fg=white>/agents</>      List all available agents');
        $output->writeln('  <fg=white>/teams</>       List all available teams');
        $output->writeln('  <fg=white>/session</>     Show current session info');
        $output->writeln('  <fg=white>/sessions</>    List all sessions');
        $output->writeln('  <fg=white>/new</>         Start a new session');
        $output->writeln('  <fg=white>/reset</>       Reset conversation context');
        $output->writeln('  <fg=white>/clear</>       Clear the screen');
        $output->writeln('  <fg=white>/history</>     Show command history');
        $output->writeln('  <fg=white>/exit</>        Exit the shell (or press Ctrl+D)');
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('<fg=white;options=bold>  Message Routing</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('  <fg=gray>Type a message to send to the default agent.</>');
        $output->writeln('  <fg=white>@agent_id message</>  <fg=gray>Route to a specific agent</>');
        $output->writeln('  <fg=white>@team_id message</>   <fg=gray>Route to a team leader</>');
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('<fg=white;options=bold>  Session Commands</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('  <fg=gray>Sessions let you maintain multiple independent conversations.</>');
        $output->writeln('  <fg=white>/new</>              <fg=gray>Start a fresh session</>');
        $output->writeln('  <fg=white>/sessions</>         <fg=gray>Show all your sessions</>');
        $output->writeln('  <fg=white>/switch N</>         <fg=gray>Switch to session #N</>');
        $output->writeln('  <fg=white>/rename X</>         <fg=gray>Rename current session to X</>');
        $output->newLine();
    }

    /**
     * List all agents.
     */
    public function listAgents(OutputStyle $output, string $defaultAgentId): void
    {
        $agents = $this->settings->getAgents();
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('<fg=white;options=bold>  Available Agents</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();

        foreach ($agents as $id => $agent) {
            $default = $id === $defaultAgentId ? ' <fg=yellow>(default)</>' : '';
            $output->writeln("  <fg=green>@{$id}</> — <fg=white>{$agent->name}</>{$default}");
            $output->writeln("    <fg=gray>{$agent->provider}/{$agent->model}</>");
        }

        $output->newLine();
    }

    /**
     * List all teams.
     */
    public function listTeams(OutputStyle $output, ?string $defaultTeamId): void
    {
        $teams = $this->settings->getTeams();
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('<fg=white;options=bold>  Available Teams</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();

        if ($teams->isEmpty()) {
            $output->writeln('  <fg=gray>No teams configured.</>');
        } else {
            foreach ($teams as $id => $team) {
                $default = $id === $defaultTeamId ? ' <fg=yellow>(default)</>' : '';
                $output->writeln("  <fg=magenta>@{$id}</> — <fg=white>{$team->name}</>{$default}");
                $output->writeln("    <fg=gray>Leader: <fg=green>@{$team->leader_agent_id}</></>");
                $output->writeln('    <fg=gray>Agents: '.implode(', ', array_map(fn ($a) => "@{$a}", $team->getAgentIds())).'</>');
            }
        }

        $output->newLine();
    }

    /**
     * Display routing info header for a message being processed.
     */
    public function displayRoutingInfo(OutputStyle $output, string $agentId, Agent $agent, bool $isTeamRouted, ?string $teamId): void
    {
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln("<fg=white;options=bold>  Agent:</>     <fg=green>@{$agentId}</> ({$agent->name})");
        $output->writeln("<fg=white;options=bold>  Model:</>     <fg=yellow>{$agent->provider}/{$agent->model}</>");
        if ($isTeamRouted && $teamId) {
            $teams = $this->settings->getTeams();
            $teamName = $teams[$teamId]->name ?? $teamId;
            $output->writeln("<fg=white;options=bold>  Team:</>      <fg=magenta>@{$teamId}</> ({$teamName})");
        }
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();
        $output->writeln('<fg=gray>  Processing...</>');
    }

    /**
     * Display a successful response from an agent.
     */
    public function displayResponse(OutputStyle $output, string $response, float $duration): void
    {
        // Clear the "Processing..." line and show response
        $output->write("\r\033[K");
        $output->writeln('<fg=green>  Response:</>');
        $output->newLine();

        // Word wrap the response for better readability
        $wrapped = wordwrap($response, 70);
        foreach (explode("\n", $wrapped) as $line) {
            $output->writeln("  <fg=white>{$line}</>");
        }

        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln("<fg=gray>  Completed in {$duration}s</>");
        $output->newLine();
    }

    /**
     * Display an error message.
     */
    public function displayError(OutputStyle $output, string $error): void
    {
        $output->write("\r\033[K");
        $output->writeln("<error>  Error: {$error}</error>");
        $output->newLine();
        $output->writeln('  <fg=yellow>Check the logs for more details.</>');
    }

    /**
     * Display current session info.
     */
    public function showCurrentSession(OutputStyle $output, ?\App\Models\Conversation $session): void
    {
        $output->newLine();
        if ($session) {
            $title = $session->getDisplayTitle();
            $pinned = $session->is_pinned ? ' 📌' : '';
            $startedAt = $session->started_at?->diffForHumans() ?? 'N/A';
            $output->writeln("<fg=white;options=bold>  Current Session</>{$pinned}");
            $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
            $output->writeln("  <fg=gray>Title:</>  <fg=white>{$title}</>");
            $output->writeln("  <fg=gray>ID:</>    <fg=white>{$session->conversation_id}</>");
            $output->writeln("  <fg=gray>Messages:</> <fg=white>{$session->total_messages}</>");
            $output->writeln("  <fg=gray>Started:</> <fg=white>{$startedAt}</>");
        } else {
            $output->writeln('<fg=yellow>No active session.</>');
        }
        $output->newLine();
    }

    /**
     * Display list of sessions.
     */
    public function listSessions(OutputStyle $output, \Illuminate\Support\Collection $sessions): void
    {
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('<fg=white;options=bold>  Your Sessions</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();

        if ($sessions->isEmpty()) {
            $output->writeln('  <fg=gray>No sessions found. Type /new to start one.</>');
        } else {
            foreach ($sessions as $index => $session) {
                $num = $index + 1;
                $title = $session->getDisplayTitle();
                $active = $session->is_active ? ' <fg=green>(active)</>' : '';
                $pinned = $session->is_pinned ? '📌 ' : '   ';
                $time = $session->last_message_at?->diffForHumans() ?? 'never';

                $output->writeln("  <fg=white>{$pinned}{$num}.</> <fg=white>{$title}</>{$active}");
                $output->writeln("      <fg=gray>Last message: {$time}</>");
            }
        }

        $output->newLine();
        $output->writeln('<fg=gray>  Type /switch N to switch to a session.</>');
        $output->newLine();
    }

    /**
     * Show command history.
     */
    public function showHistory(OutputStyle $output, \App\Services\Shell\ShellHistoryManager $historyManager): void
    {
        $output->newLine();
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('<fg=white;options=bold>  Command History</>');
        $output->writeln('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->newLine();

        if ($historyManager->isEmpty()) {
            $output->writeln('  <fg=gray>No history yet.</>');
        } else {
            $recent = $historyManager->getRecent(20);
            $allHistory = $historyManager->getAll();
            $start = count($allHistory) - count($recent);
            foreach ($recent as $i => $entry) {
                $num = str_pad((string) ($start + $i + 1), 4, ' ', STR_PAD_LEFT);
                $output->writeln("  <fg=gray>{$num}.</> <fg=white>{$entry}</>");
            }
        }

        $output->newLine();
    }

    /**
     * Strip ANSI color codes from a string.
     */
    public function stripAnsi(string $string): string
    {
        return preg_replace('/\e\[[\d;]*m/', '', $string);
    }
}
