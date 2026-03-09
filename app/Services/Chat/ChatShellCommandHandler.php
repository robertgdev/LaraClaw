<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Services\SessionService;
use App\Services\SettingsService;
use App\Services\Shell\ShellHistoryManager;
use Illuminate\Console\OutputStyle;

/**
 * Handles all slash-commands for the interactive chat shell.
 *
 * Dispatches /help, /agent, /team, /agents, /teams, /session,
 * /sessions, /new, /switch, /rename, /reset, /clear, /history,
 * /exit commands and delegates rendering to ChatShellRenderer.
 */
class ChatShellCommandHandler
{
    protected SettingsService $settings;

    protected SessionService $sessionService;

    protected ChatShellRenderer $renderer;

    public function __construct(
        SettingsService $settings,
        SessionService $sessionService,
        ChatShellRenderer $renderer,
    ) {
        $this->settings = $settings;
        $this->sessionService = $sessionService;
        $this->renderer = $renderer;
    }

    /**
     * Handle a shell command (starting with /).
     *
     * @return string|null Returns 'exit' to signal the REPL should stop, null otherwise
     */
    public function handle(
        string $input,
        OutputStyle $output,
        string &$defaultAgentId,
        ?string &$defaultTeamId,
        bool &$shouldReset,
        ?Conversation &$currentSession,
        string $senderId,
        ShellHistoryManager $historyManager,
    ): ?string {
        $parts = explode(' ', $input, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? null;

        return match ($command) {
            '/exit', '/quit', '/q' => $this->exitShell($output),
            '/help', '/?' => $this->showHelp($output),
            '/agent' => $this->handleAgentCommand($output, $args, $defaultAgentId, $defaultTeamId),
            '/team' => $this->handleTeamCommand($output, $args, $defaultAgentId, $defaultTeamId),
            '/agents' => $this->listAgents($output, $defaultAgentId),
            '/teams' => $this->listTeams($output, $defaultTeamId),
            '/session' => $this->showCurrentSession($output, $currentSession),
            '/sessions' => $this->listSessions($output, $senderId),
            '/new' => $this->startNewSession($output, $senderId, $shouldReset, $currentSession),
            '/switch' => $this->switchSession($output, $args, $senderId, $shouldReset, $currentSession),
            '/rename' => $this->renameSession($output, $args, $currentSession),
            '/reset' => $this->resetConversation($output, $shouldReset),
            '/clear' => $this->clearScreen($output),
            '/history' => $this->showHistory($output, $historyManager),
            default => $this->unknownCommand($output, $command),
        };
    }

    protected function exitShell(OutputStyle $output): string
    {
        $output->newLine();
        $output->writeln('<fg=gray>Goodbye! 👋</>');

        return 'exit';
    }

    protected function showHelp(OutputStyle $output): ?string
    {
        $this->renderer->showHelp($output);

        return null;
    }

    protected function handleAgentCommand(OutputStyle $output, ?string $args, string &$defaultAgentId, ?string &$defaultTeamId): ?string
    {
        if ($args === null) {
            $agents = $this->settings->getAgents();
            $agent = $agents[$defaultAgentId] ?? null;
            $output->newLine();
            if ($agent) {
                $output->writeln("<fg=gray>Current default agent:</> <fg=green>@{$defaultAgentId}</> ({$agent->name})");
            } else {
                $output->writeln('<fg=yellow>No default agent set.</>');
            }
            $output->newLine();

            return null;
        }

        $agentId = ltrim(trim($args), '@');
        $agents = $this->settings->getAgents();

        if (! isset($agents[$agentId])) {
            $output->writeln("<error>Agent '@{$agentId}' not found.</error>");
            $output->writeln('<fg=gray>Available agents: '.implode(', ', array_map(fn ($id) => "@{$id}", array_keys($agents->toArray()))).'</>');

            return null;
        }

        $defaultAgentId = $agentId;
        $output->writeln("<fg=green>✓</> Default agent set to <fg=green>@{$agentId}</> ({$agents[$agentId]->name})");

        return null;
    }

    protected function handleTeamCommand(OutputStyle $output, ?string $args, string &$defaultAgentId, ?string &$defaultTeamId): ?string
    {
        if ($args === null) {
            $output->newLine();
            if ($defaultTeamId) {
                $teams = $this->settings->getTeams();
                $team = $teams[$defaultTeamId] ?? null;
                if ($team) {
                    $output->writeln("<fg=gray>Current default team:</> <fg=magenta>@{$defaultTeamId}</> ({$team->name})");
                }
            } else {
                $output->writeln('<fg=yellow>No default team set.</>');
            }
            $output->newLine();

            return null;
        }

        $teamId = ltrim(trim($args), '@');
        $teams = $this->settings->getTeams();

        if (! isset($teams[$teamId])) {
            $output->writeln("<error>Team '@{$teamId}' not found.</error>");
            $output->writeln('<fg=gray>Available teams: '.implode(', ', array_map(fn ($id) => "@{$id}", array_keys($teams->toArray()))).'</>');

            return null;
        }

        $defaultTeamId = $teamId;
        $defaultAgentId = $teams[$teamId]->leader_agent_id;
        $output->writeln("<fg=green>✓</> Default team set to <fg=magenta>@{$teamId}</> ({$teams[$teamId]->name})");
        $output->writeln("<fg=gray>  Leader agent: <fg=green>@{$defaultAgentId}</></>");

        return null;
    }

    protected function listAgents(OutputStyle $output, string $defaultAgentId): ?string
    {
        $this->renderer->listAgents($output, $defaultAgentId);

        return null;
    }

    protected function listTeams(OutputStyle $output, ?string $defaultTeamId): ?string
    {
        $this->renderer->listTeams($output, $defaultTeamId);

        return null;
    }

    protected function showCurrentSession(OutputStyle $output, ?Conversation $currentSession): ?string
    {
        $this->renderer->showCurrentSession($output, $currentSession);

        return null;
    }

    protected function listSessions(OutputStyle $output, string $senderId): ?string
    {
        $sessions = $this->sessionService->getSessions(ChannelEnum::CLI, $senderId);
        $this->renderer->listSessions($output, $sessions);

        return null;
    }

    protected function startNewSession(OutputStyle $output, string $senderId, bool &$shouldReset, ?Conversation &$currentSession): ?string
    {
        $currentSession = $this->sessionService->createSession(
            ChannelEnum::CLI,
            $senderId,
            'shell-user'
        );

        $shouldReset = true;

        $output->newLine();
        $output->writeln('<fg=green>✓</> Started new session.');
        $output->writeln("<fg=gray>  ID: {$currentSession->conversation_id}</>");
        $output->newLine();

        return null;
    }

    protected function switchSession(OutputStyle $output, ?string $args, string $senderId, bool &$shouldReset, ?Conversation &$currentSession): ?string
    {
        if ($args === null) {
            $output->writeln('<error>Usage: /switch N (where N is the session number)</error>');

            return null;
        }

        $sessionNum = (int) trim($args);
        if ($sessionNum < 1) {
            $output->writeln('<error>Invalid session number. Use /sessions to see available sessions.</error>');

            return null;
        }

        $sessions = $this->sessionService->getSessions(ChannelEnum::CLI, $senderId);
        $session = $sessions->get($sessionNum - 1);

        if (! $session) {
            $output->writeln("<error>Session #{$sessionNum} not found. Use /sessions to see available sessions.</error>");

            return null;
        }

        $currentSession = $this->sessionService->switchToSession(
            $session->conversation_id,
            ChannelEnum::CLI,
            $senderId
        );

        $shouldReset = true;

        $title = $currentSession->getDisplayTitle();
        $output->newLine();
        $output->writeln("<fg=green>✓</> Switched to session: <fg=white>{$title}</>");
        $output->newLine();

        return null;
    }

    protected function renameSession(OutputStyle $output, ?string $args, ?Conversation $currentSession): ?string
    {
        if ($args === null) {
            $output->writeln('<error>Usage: /rename New Name</error>');

            return null;
        }

        if (! $currentSession) {
            $output->writeln('<error>No active session. Use /new to start one.</error>');

            return null;
        }

        $newName = trim($args);
        $this->sessionService->renameSession(
            $currentSession->conversation_id,
            $newName,
            $currentSession->channel,
            $currentSession->sender_id
        );
        $currentSession->refresh();

        $output->newLine();
        $output->writeln("<fg=green>✓</> Session renamed to: <fg=white>{$newName}</>");
        $output->newLine();

        return null;
    }

    protected function resetConversation(OutputStyle $output, bool &$shouldReset): ?string
    {
        $shouldReset = true;
        $output->writeln('<fg=green>✓</> Conversation context will be reset on next message.');

        return null;
    }

    protected function clearScreen(OutputStyle $output): ?string
    {
        $output->write("\033[2J\033[H");

        return null;
    }

    protected function showHistory(OutputStyle $output, ShellHistoryManager $historyManager): ?string
    {
        $this->renderer->showHistory($output, $historyManager);

        return null;
    }

    protected function unknownCommand(OutputStyle $output, string $command): ?string
    {
        $output->writeln("<error>Unknown command: {$command}</error>");
        $output->writeln('<fg=gray>Type /help to see available commands.</>');

        return null;
    }
}
