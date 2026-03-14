<?php

declare(strict_types=1);

namespace App\Services\Commands;

use App\DTOs\CommandResponseDTO;
use App\Logging\MultiLogger;
use function Safe\preg_match;
use function Safe\preg_split;

/**
 * Handles channel-specific commands for Discord, Telegram, WhatsApp, etc.
 *
 * These commands use ! or / prefix and have slightly different behavior
 * than WebSocket commands (e.g., /agent instead of /agents).
 */
class ChannelCommandHandler
{
    public function __construct(
        protected SlashCommandHandler $slashHandler
    ) {}

    /**
     * Handle channel commands (for Discord, Telegram, WhatsApp, etc.).
     *
     * @return CommandResponseDTO|null Returns null if not a command
     */
    public function handle(string $message): ?CommandResponseDTO
    {
        $text = trim($message);

        if (! preg_match('/^[!\/]/', $text)) {
            return null;
        }

        $parts = preg_split('/\s+/', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? null;

        MultiLogger::info("Processing channel command: {$command}");

        return match ($command) {
            '/agent', '!agent', '/agents', '!agents' => $this->slashHandler->getAgents(),
            '/team', '!team', '/teams', '!teams' => $this->slashHandler->getTeams(),
            '/reset', '!reset' => $this->slashHandler->handleResetCommand($args),
            default => null,
        };
    }

    /**
     * Check if a message is a channel command.
     */
    public function isChannelCommand(string $message): bool
    {
        $text = trim($message);

        if (! preg_match('/^[!\/]/', $text)) {
            return false;
        }

        $parts = preg_split('/\s+/', $text, 2);
        $command = strtolower($parts[0]);

        return in_array($command, [
            '/agent', '!agent', '/agents', '!agents',
            '/team', '!team', '/teams', '!teams',
            '/reset', '!reset',
        ]);
    }
}
