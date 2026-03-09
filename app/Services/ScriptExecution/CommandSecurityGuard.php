<?php

declare(strict_types=1);

namespace App\Services\ScriptExecution;

/**
 * Guards against dangerous command patterns.
 *
 * Maintains a configurable blocklist and checks commands against it.
 */
class CommandSecurityGuard
{
    /**
     * Default blocked command patterns.
     */
    protected array $defaultBlockedCommands = [
        'rm -rf /',
        'rm -rf /*',
        'sudo ',
        'chmod 777',
        'chmod -R 777',
        'mkfs',
        'dd if=',
        'dd if=/dev/',
        '> /dev/sd',
        '> /dev/hd',
        ':(){ :|:& };:', // Fork bomb
        'curl | bash',
        'wget | bash',
        'curl | sh',
        'wget | sh',
    ];

    protected array $blockedCommands;

    public function __construct(?array $configBlockedCommands = null)
    {
        $this->blockedCommands = $configBlockedCommands ?? $this->defaultBlockedCommands;
    }

    /**
     * Check if a command contains blocked patterns.
     */
    public function isBlocked(string $command): bool
    {
        $commandLower = strtolower($command);

        foreach ($this->blockedCommands as $blocked) {
            if (str_contains($commandLower, strtolower($blocked))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the blocked pattern in a command.
     */
    public function findBlockedPattern(string $command): ?string
    {
        $commandLower = strtolower($command);

        foreach ($this->blockedCommands as $blocked) {
            if (str_contains($commandLower, strtolower($blocked))) {
                return $blocked;
            }
        }

        return null;
    }

    /**
     * Get the list of blocked command patterns.
     *
     * @return array<string>
     */
    public function getBlockedCommands(): array
    {
        return $this->blockedCommands;
    }
}
