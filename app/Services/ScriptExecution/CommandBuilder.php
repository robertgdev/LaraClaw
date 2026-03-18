<?php

declare(strict_types=1);

namespace App\Services\ScriptExecution;

use function Safe\chmod;

/**
 * Builds shell commands from script paths and arguments.
 *
 * Handles interpreter selection based on file extension,
 * argument escaping, and executable permissions.
 */
class CommandBuilder
{
    /**
     * Build a command string from script path and arguments.
     *
     * @param  string  $scriptPath  The full path to the script
     * @param  array<int, string>  $args  Arguments to pass
     * @return string The full command string
     */
    public function build(string $scriptPath, array $args): string
    {
        // Ensure script is executable for shell scripts
        if (str_ends_with($scriptPath, '.sh') && ! is_executable($scriptPath)) {
            chmod($scriptPath, 0755);
        }

        // Determine interpreter based on extension
        $interpreter = $this->getInterpreter($scriptPath);

        // Escape arguments
        $escapedArgs = array_map('escapeshellarg', $args);
        $argsString = implode(' ', $escapedArgs);

        // Build command
        $escapedPath = escapeshellarg($scriptPath);

        if ($interpreter) {
            return "{$interpreter} {$escapedPath} {$argsString}";
        }

        // Direct execution for executable scripts
        return "{$escapedPath} {$argsString}";
    }

    /**
     * Get the interpreter for a script based on its extension.
     */
    public function getInterpreter(string $scriptPath): ?string
    {
        $extension = pathinfo($scriptPath, PATHINFO_EXTENSION);

        return match ($extension) {
            'sh' => 'bash',
            'py' => 'python3',
            'ts' => 'npx ts-node',
            'js' => 'node',
            default => null,
        };
    }
}
