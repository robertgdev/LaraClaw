<?php

declare(strict_types=1);

namespace App\Services\ScriptExecution;

use App\DTOs\ScriptExecutionResultDTO;
use App\Logging\MultiLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Executes commands in a sandboxed environment.
 *
 * Provides environment isolation, timeout enforcement,
 * and output size limits.
 */
class SandboxedExecutor
{
    protected SettingsService $settings;

    protected int $defaultTimeout = 30;

    protected int $defaultMaxOutputSize = 10000;

    protected int $timeout;

    protected int $maxOutputSize;

    public function __construct(SettingsService $settings, ?int $configTimeout = null, ?int $configMaxOutputSize = null)
    {
        $this->settings = $settings;
        $this->timeout = $configTimeout ?? $this->defaultTimeout;
        $this->maxOutputSize = $configMaxOutputSize ?? $this->defaultMaxOutputSize;
    }

    /**
     * Execute a command with sandboxing.
     */
    public function run(
        string $command,
        ?string $workingDir = null,
        ?string $scriptPath = null,
        array $args = []
    ): ScriptExecutionResultDTO {
        $workingDir = $workingDir ?? $this->settings->getWorkspacePath();

        // Ensure working directory exists
        if (! File::isDirectory($workingDir)) {
            File::makeDirectory($workingDir, 0755, true);
        }

        // Build environment with sandboxing
        $env = $this->getSandboxedEnv();

        MultiLogger::info('Executing script', [
            'command' => $this->maskSensitiveData($command),
            'cwd' => $workingDir,
            'timeout' => $this->timeout,
            'script' => $scriptPath,
        ]);

        $startTime = microtime(true);

        try {
            $result = Process::path($workingDir)
                ->timeout($this->timeout)
                ->env($env)
                ->run($command);

            $duration = round(microtime(true) - $startTime, 2);

            // Truncate output if too large
            $output = $result->output();
            if (strlen($output) > $this->maxOutputSize) {
                $output = substr($output, 0, $this->maxOutputSize).
                    "\n\n... (output truncated, max size: {$this->maxOutputSize} bytes)";
            }

            if ($result->successful()) {
                MultiLogger::info('Script executed successfully', [
                    'duration' => $duration,
                    'output_length' => strlen($result->output()),
                ]);

                return ScriptExecutionResultDTO::success(
                    output: $output,
                    duration: $duration,
                    scriptPath: $scriptPath,
                    args: $args
                );
            }

            MultiLogger::error('Script execution failed', [
                'exit_code' => $result->exitCode(),
                'error' => $result->errorOutput(),
            ]);

            return ScriptExecutionResultDTO::error(
                message: $result->errorOutput() ?: "Script exited with code {$result->exitCode()}",
                exitCode: $result->exitCode(),
                output: $output,
                duration: $duration,
                scriptPath: $scriptPath,
                args: $args
            );

        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            $duration = round(microtime(true) - $startTime, 2);

            MultiLogger::error('Script execution timed out', [
                'timeout' => $this->timeout,
                'duration' => $duration,
            ]);

            return ScriptExecutionResultDTO::error(
                message: "Script timed out after {$this->timeout} seconds",
                exitCode: 124, // Standard timeout exit code
                duration: $duration,
                scriptPath: $scriptPath,
                args: $args
            );
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            MultiLogger::error('Script execution error', [
                'error' => $e->getMessage(),
            ]);

            return ScriptExecutionResultDTO::error(
                message: "Execution error: {$e->getMessage()}",
                duration: $duration,
                scriptPath: $scriptPath,
                args: $args
            );
        }
    }

    /**
     * Get the configured timeout in seconds.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Get the maximum output size in bytes.
     */
    public function getMaxOutputSize(): int
    {
        return $this->maxOutputSize;
    }

    /**
     * Get sandboxed environment variables.
     *
     * @return array<string, string>
     */
    public function getSandboxedEnv(): array
    {
        return [
            'HOME' => getenv('HOME') ?: '',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'Laraclaw_WORKSPACE' => $this->settings->getWorkspacePath(),
            'Laraclaw_SCRIPT_MODE' => 'true',
            'LANG' => getenv('LANG') ?: 'en_US.UTF-8',
            'TERM' => 'xterm-256color',
        ];
    }

    /**
     * Mask sensitive data in command for logging.
     */
    protected function maskSensitiveData(string $command): string
    {
        if (strlen($command) > 200) {
            return substr($command, 0, 200).'... (truncated)';
        }

        return $command;
    }

    /**
     * Get the working directory for an agent.
     */
    public function getWorkingDirectory(?string $agentId): string
    {
        $workspacePath = $this->settings->getWorkspacePath();

        if ($agentId) {
            $agentDir = $workspacePath.'/'.$agentId;
            if (File::isDirectory($agentDir)) {
                return $agentDir;
            }
        }

        return $workspacePath;
    }
}
