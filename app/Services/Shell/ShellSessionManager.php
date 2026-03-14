<?php

declare(strict_types=1);

namespace App\Services\Shell;

use App\Enums\ChannelEnum;
use App\Models\Conversation;
use App\Services\SessionService;
use Illuminate\Console\Command;

/**
 * Manages CLI shell sessions: create, list, switch, rename.
 *
 * Provides session management UI output via an Artisan Command instance.
 */
class ShellSessionManager
{
    protected SessionService $sessionService;

    protected ?Conversation $currentSession = null;

    protected string $senderId;

    public function __construct(SessionService $sessionService, string $senderId = 'cli-user')
    {
        $this->sessionService = $sessionService;
        $this->senderId = $senderId;
    }

    /**
     * Get or create the active session.
     */
    public function getOrCreateActiveSession(): Conversation
    {
        $this->currentSession = $this->sessionService->getOrCreateActiveSession(
            ChannelEnum::CLI,
            $this->senderId,
            'shell-user'
        );

        return $this->currentSession;
    }

    /**
     * Get the current session.
     */
    public function getCurrentSession(): ?Conversation
    {
        return $this->currentSession;
    }

    /**
     * Set the current session.
     */
    public function setCurrentSession(Conversation $session): void
    {
        $this->currentSession = $session;
    }

    /**
     * Show current session info.
     */
    public function showCurrentSession(Command $command): void
    {
        $command->newLine();
        if ($this->currentSession) {
            $title = $this->currentSession->getDisplayTitle();
            $pinned = $this->currentSession->is_pinned ? ' 📌' : '';
            $startedAt = $this->currentSession->started_at?->diffForHumans() ?? 'N/A';
            $command->line("<fg=white;options=bold>  Current Session</>{$pinned}");
            $command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
            $command->line("  <fg=gray>Title:</>  <fg=white>{$title}</>");
            $command->line("  <fg=gray>ID:</>    <fg=white>{$this->currentSession->conversation_id}</>");
            $command->line("  <fg=gray>Messages:</> <fg=white>{$this->currentSession->total_messages}</>");
            $command->line("  <fg=gray>Started:</> <fg=white>{$startedAt}</>");
        } else {
            $command->line('<fg=yellow>No active session.</>');
        }
        $command->newLine();
    }

    /**
     * List all sessions for the current sender.
     */
    public function listSessions(Command $command): void
    {
        $sessions = $this->sessionService->getSessions(ChannelEnum::CLI, $this->senderId);

        $command->newLine();
        $command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $command->line('<fg=white;options=bold>  Your Sessions</>');
        $command->line('<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $command->newLine();

        if ($sessions->isEmpty()) {
            $command->line('  <fg=gray>No sessions found. Type /new to start one.</>');
        } else {
            foreach ($sessions as $index => $session) {
                $num = $index + 1;
                $title = $session->getDisplayTitle();
                $active = $session->is_active ? ' <fg=green>(active)</>' : '';
                $pinned = $session->is_pinned ? '📌 ' : '   ';
                $time = $session->last_message_at?->diffForHumans() ?? 'never';

                $command->line("  <fg=white>{$pinned}{$num}.</> <fg=white>{$title}</>{$active}");
                $command->line("      <fg=gray>Last message: {$time}</>");
            }
        }

        $command->newLine();
        $command->line('<fg=gray>  Type /switch N to switch to a session.</>');
        $command->newLine();
    }

    /**
     * Start a new session.
     *
     * @return bool Always returns true to indicate reset needed
     */
    public function startNewSession(Command $command): bool
    {
        $this->currentSession = $this->sessionService->createSession(
            ChannelEnum::CLI,
            $this->senderId,
            'shell-user'
        );

        $command->newLine();
        $command->line('<fg=green>✓</> Started new session.');
        $command->line("<fg=gray>  ID: {$this->currentSession->conversation_id}</>");
        $command->newLine();

        return true;
    }

    /**
     * Switch to a different session.
     *
     * @return bool True if reset needed after switching
     */
    public function switchSession(Command $command, ?string $args): bool
    {
        if ($args === null) {
            $command->error('Usage: /switch N (where N is the session number)');

            return false;
        }

        $sessionNum = (int) trim($args);
        if ($sessionNum < 1) {
            $command->error('Invalid session number. Use /sessions to see available sessions.');

            return false;
        }

        $sessions = $this->sessionService->getSessions(ChannelEnum::CLI, $this->senderId);
        $session = $sessions->get($sessionNum - 1);

        if (! $session) {
            $command->error("Session #{$sessionNum} not found. Use /sessions to see available sessions.");

            return false;
        }

        $this->currentSession = $this->sessionService->switchToSession(
            $this->senderId,
            ChannelEnum::CLI,
            $session->conversation_id
        );

        $title = $this->currentSession->getDisplayTitle();
        $command->newLine();
        $command->line("<fg=green>✓</> Switched to session: <fg=white>{$title}</>");
        $command->newLine();

        return true;
    }

    /**
     * Rename the current session.
     */
    public function renameSession(Command $command, ?string $args): void
    {
        if ($args === null) {
            $command->error('Usage: /rename New Name');

            return;
        }

        if (! $this->currentSession) {
            $command->error('No active session. Use /new to start one.');

            return;
        }

        $newName = trim($args);
        $this->sessionService->renameSession(
            $this->currentSession->conversation_id,
            $newName,
            ChannelEnum::CLI,
            $this->senderId
        );
        $this->currentSession->refresh();

        $command->newLine();
        $command->line("<fg=green>✓</> Session renamed to: <fg=white>{$newName}</>");
        $command->newLine();
    }
}
