<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ScriptExecutionResultDTO;
use App\Logging\MultiLogger;
use App\Services\ScriptExecution\CommandBuilder;
use App\Services\ScriptExecution\CommandSecurityGuard;
use App\Services\ScriptExecution\SandboxedExecutor;
use App\Services\ScriptExecution\ScriptValidator;

/**
 * Service for executing skill scripts with security sandboxing.
 *
 * This service orchestrates script execution by delegating to:
 * - {@see ScriptValidator} for path resolution and extension/directory checks
 * - {@see CommandSecurityGuard} for blocked command pattern detection
 * - {@see CommandBuilder} for interpreter selection and argument escaping
 * - {@see SandboxedExecutor} for isolated process execution
 */
class ScriptExecutionService
{
    protected SettingsService $settings;

    protected SkillSearchService $skillSearch;

    protected ScriptValidator $validator;

    protected CommandSecurityGuard $securityGuard;

    protected CommandBuilder $commandBuilder;

    protected SandboxedExecutor $executor;

    protected array $config;

    /**
     * Create a new ScriptExecutionService instance.
     */
    public function __construct(
        SettingsService $settings,
        SkillSearchService $skillSearch
    ) {
        $this->settings = $settings;
        $this->skillSearch = $skillSearch;
        $this->config = config('laraclaw.script_execution', []);

        $this->validator = new ScriptValidator(
            $skillSearch,
            $this->config['allowed_extensions'] ?? null
        );
        $this->securityGuard = new CommandSecurityGuard(
            $this->config['blocked_commands'] ?? null
        );
        $this->commandBuilder = new CommandBuilder;
        $this->executor = new SandboxedExecutor(
            $settings,
            isset($this->config['timeout']) ? (int) $this->config['timeout'] : null,
            isset($this->config['max_output_size']) ? (int) $this->config['max_output_size'] : null
        );
    }

    /**
     * Execute a skill script with arguments.
     *
     * @param  string  $skillName  Name of the skill (e.g., 'schedule')
     * @param  string  $scriptName  Script filename (e.g., 'schedule.sh')
     * @param  array  $args  Arguments to pass to the script
     * @param  string|null  $agentId  Agent context for working directory
     */
    public function execute(
        string $skillName,
        string $scriptName,
        array $args = [],
        ?string $agentId = null
    ): ScriptExecutionResultDTO {
        // Check if script execution is enabled
        if (! $this->isEnabled()) {
            return ScriptExecutionResultDTO::error(
                'Script execution is disabled in configuration.',
                scriptPath: $scriptName,
                args: $args
            );
        }

        // 1. Validate skill exists
        $skill = $this->skillSearch->getSkill($skillName);
        if (! $skill) {
            MultiLogger::error('Skill not found', [
                'skill_name' => $skillName,
                'available_skills' => array_keys($this->skillSearch->getAllSkills()),
            ]);

            return ScriptExecutionResultDTO::error(
                "Skill not found: {$skillName}",
                scriptPath: $scriptName,
                args: $args
            );
        }

        // 2. Validate script exists
        $scriptPath = $this->validator->resolveScriptPath($skill, $scriptName);
        if (! $scriptPath) {
            MultiLogger::error('Script not found in skill', [
                'script_name' => $scriptName,
                'skill_name' => $skillName,
                'skill_directory' => $skill['directory'] ?? 'unknown',
                'scripts_dir' => ($skill['directory'] ?? '').'/scripts',
            ]);

            return ScriptExecutionResultDTO::error(
                "Script not found: {$scriptName}",
                scriptPath: $scriptName,
                args: $args
            );
        }

        // 3. Validate script extension is allowed
        if (! $this->validator->isExtensionAllowed($scriptPath)) {
            $extension = pathinfo($scriptPath, PATHINFO_EXTENSION);

            return ScriptExecutionResultDTO::error(
                "Script extension '.{$extension}' is not allowed. Allowed extensions: ".
                implode(', ', $this->validator->getAllowedExtensions()),
                scriptPath: $scriptPath,
                args: $args
            );
        }

        // 4. Validate script is in an allowed directory
        if (! $this->validator->isScriptInAllowedDir($scriptPath)) {
            return ScriptExecutionResultDTO::error(
                "Script is not in an allowed skill directory: {$scriptName}",
                scriptPath: $scriptPath,
                args: $args
            );
        }

        // 5. Build command with arguments
        $command = $this->commandBuilder->build($scriptPath, $args);

        // 6. Check for blocked commands
        if ($this->securityGuard->isBlocked($command)) {
            return ScriptExecutionResultDTO::error(
                'Command contains blocked pattern. Execution denied for security.',
                scriptPath: $scriptPath,
                args: $args
            );
        }

        // 7. Determine working directory
        $workingDir = $this->executor->getWorkingDirectory($agentId);

        // 8. Execute with sandboxing
        return $this->executor->run($command, $workingDir, $scriptPath, $args);
    }

    /**
     * Execute a command string directly.
     *
     * This method is used internally and can also be used for
     * executing commands from AI responses.
     *
     * @param  string  $command  The full command to execute
     * @param  string|null  $workingDir  Working directory for execution
     * @param  string|null  $scriptPath  Path to the script (for logging)
     * @param  array  $args  Arguments passed (for logging)
     */
    public function executeCommand(
        string $command,
        ?string $workingDir = null,
        ?string $scriptPath = null,
        array $args = []
    ): ScriptExecutionResultDTO {
        return $this->executor->run($command, $workingDir, $scriptPath, $args);
    }

    /**
     * Check if script execution is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Get the configured timeout in seconds.
     */
    public function getTimeout(): int
    {
        return $this->executor->getTimeout();
    }

    /**
     * Get the maximum output size in bytes.
     */
    public function getMaxOutputSize(): int
    {
        return $this->executor->getMaxOutputSize();
    }

    /**
     * Get the list of allowed script extensions.
     *
     * @return array<string>
     */
    public function getAllowedExtensions(): array
    {
        return $this->validator->getAllowedExtensions();
    }

    /**
     * Get the list of blocked command patterns.
     *
     * @return array<string>
     */
    public function getBlockedCommands(): array
    {
        return $this->securityGuard->getBlockedCommands();
    }

    /**
     * Validate a script path without executing it.
     *
     * @return array{valid: bool, error: string|null, path: string|null}
     */
    public function validateScript(string $skillName, string $scriptName): array
    {
        return $this->validator->validate($skillName, $scriptName, $this->isEnabled());
    }

    /**
     * Get the skill search service.
     * Used by ResponseParser to find skills for scripts.
     */
    public function getSkillSearch(): SkillSearchService
    {
        return $this->skillSearch;
    }

    /**
     * Execute a direct shell command (not from a skill script).
     *
     * This method is used for skills that don't have executable scripts,
     * allowing the LLM to output direct commands like `curl "wttr.in/Belgrade?format=3"`.
     * The command is validated against the blocked_commands list for security.
     *
     * @param  string  $command  The command to execute
     * @param  string|null  $workingDir  Working directory for execution
     */
    public function executeDirectCommand(
        string $command,
        ?string $workingDir = null
    ): ScriptExecutionResultDTO {
        // Check if script execution is enabled
        if (! $this->isEnabled()) {
            return ScriptExecutionResultDTO::error(
                'Script execution is disabled in configuration.',
                scriptPath: 'direct',
                args: []
            );
        }

        // Check for blocked commands
        if ($this->securityGuard->isBlocked($command)) {
            $blockedPattern = $this->securityGuard->findBlockedPattern($command);
            MultiLogger::warning('Blocked direct command attempted', [
                'command' => strlen($command) > 200 ? substr($command, 0, 200).'...' : $command,
                'blocked_pattern' => $blockedPattern,
            ]);

            return ScriptExecutionResultDTO::error(
                "Command blocked for security. Command: `{$command}` contains blocked pattern: `{$blockedPattern}`",
                scriptPath: 'direct',
                args: []
            );
        }

        // Determine working directory
        $workingDir = $workingDir ?? $this->settings->getWorkspacePath();

        MultiLogger::info('Executing direct command', [
            'command' => strlen($command) > 200 ? substr($command, 0, 200).'...' : $command,
            'cwd' => $workingDir,
        ]);

        // Execute the command
        return $this->executor->run($command, $workingDir, 'direct', []);
    }

    /**
     * Check if a command contains blocked patterns (public method).
     *
     * @param  string  $command  The command to check
     * @return bool True if the command contains blocked patterns
     */
    public function isCommandBlocked(string $command): bool
    {
        return $this->securityGuard->isBlocked($command);
    }

    // ==========================================
    // Accessor Methods for Extracted Components
    // ==========================================

    /**
     * Get the script validator.
     */
    public function getValidator(): ScriptValidator
    {
        return $this->validator;
    }

    /**
     * Get the command security guard.
     */
    public function getSecurityGuard(): CommandSecurityGuard
    {
        return $this->securityGuard;
    }

    /**
     * Get the command builder.
     */
    public function getCommandBuilder(): CommandBuilder
    {
        return $this->commandBuilder;
    }

    /**
     * Get the sandboxed executor.
     */
    public function getExecutor(): SandboxedExecutor
    {
        return $this->executor;
    }
}
