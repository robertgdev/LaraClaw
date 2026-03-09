<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object for script execution results.
 *
 * Contains the output, error, exit code, and timing information
 * from executing a skill script.
 */
readonly class ScriptExecutionResult
{
    /**
     * @param  bool  $success  Whether the script executed successfully
     * @param  string  $output  The stdout output from the script
     * @param  string  $error  The stderr output or error message
     * @param  int  $exitCode  The process exit code
     * @param  float  $duration  Execution time in seconds
     * @param  string|null  $scriptPath  The path to the executed script
     * @param  array  $args  The arguments passed to the script
     */
    public function __construct(
        public bool $success,
        public string $output,
        public string $error,
        public int $exitCode,
        public float $duration,
        public ?string $scriptPath = null,
        public array $args = [],
    ) {}

    /**
     * Create a successful execution result.
     *
     * @param  string  $output  The script output
     * @param  float  $duration  Execution time in seconds
     * @param  string|null  $scriptPath  The path to the executed script
     * @param  array  $args  The arguments passed to the script
     */
    public static function success(
        string $output,
        float $duration = 0,
        ?string $scriptPath = null,
        array $args = []
    ): self {
        return new self(
            success: true,
            output: $output,
            error: '',
            exitCode: 0,
            duration: $duration,
            scriptPath: $scriptPath,
            args: $args
        );
    }

    /**
     * Create a failed execution result.
     *
     * @param  string  $message  The error message
     * @param  int  $exitCode  The process exit code
     * @param  string  $output  Any partial output before failure
     * @param  float  $duration  Execution time in seconds
     * @param  string|null  $scriptPath  The path to the attempted script
     * @param  array  $args  The arguments passed to the script
     */
    public static function error(
        string $message,
        int $exitCode = 1,
        string $output = '',
        float $duration = 0,
        ?string $scriptPath = null,
        array $args = []
    ): self {
        return new self(
            success: false,
            output: $output,
            error: $message,
            exitCode: $exitCode,
            duration: $duration,
            scriptPath: $scriptPath,
            args: $args
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'error' => $this->error,
            'exit_code' => $this->exitCode,
            'duration' => $this->duration,
            'script_path' => $this->scriptPath,
            'args' => $this->args,
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Get a formatted output string for display.
     */
    public function getFormattedOutput(): string
    {
        if ($this->success) {
            $output = $this->output;
            if ($this->duration > 0) {
                $output .= "\n\n_Execution time: {$this->duration}s_";
            }

            return $output;
        }

        return "**Error (exit code {$this->exitCode}):** {$this->error}";
    }
}
