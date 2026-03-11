<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Data Transfer Object for parsed AI responses.
 *
 * Contains the original response, the modified response after
 * script execution, and details of any executed scripts.
 */
readonly class ParsedResponseDTO
{
    /**
     * @param  string  $originalResponse  The original AI response before parsing
     * @param  string  $modifiedResponse  The response after script execution replacements
     * @param  array<ScriptExecutionResultDTO>  $executions  List of script execution results
     * @param  array  $actions  List of actions performed (for logging/debugging)
     */
    public function __construct(
        public string $originalResponse,
        public string $modifiedResponse,
        public array $executions = [],
        public array $actions = [],
    ) {}

    /**
     * Check if any scripts were executed.
     */
    public function hasExecutions(): bool
    {
        return ! empty($this->executions);
    }

    /**
     * Get the count of successful executions.
     */
    public function getSuccessCount(): int
    {
        return count(array_filter(
            $this->executions,
            fn (ScriptExecutionResultDTO $r) => $r->success
        ));
    }

    /**
     * Get the count of failed executions.
     */
    public function getFailureCount(): int
    {
        return count(array_filter(
            $this->executions,
            fn (ScriptExecutionResultDTO $r) => ! $r->success
        ));
    }

    /**
     * Check if all executions were successful.
     */
    public function allSuccessful(): bool
    {
        if (empty($this->executions)) {
            return true;
        }

        return $this->getFailureCount() === 0;
    }

    /**
     * Get the combined output from all executions.
     */
    public function getCombinedOutput(): string
    {
        $outputs = array_map(
            fn (ScriptExecutionResultDTO $r) => $r->output,
            $this->executions
        );

        return implode("\n\n---\n\n", array_filter($outputs));
    }

    /**
     * Get all error messages from failed executions.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return array_map(
            fn (ScriptExecutionResultDTO $r) => $r->error,
            array_filter($this->executions, fn (ScriptExecutionResultDTO $r) => ! $r->success)
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
            'original_response' => $this->originalResponse,
            'modified_response' => $this->modifiedResponse,
            'executions' => array_map(
                fn (ScriptExecutionResultDTO $r) => $r->toArray(),
                $this->executions
            ),
            'actions' => $this->actions,
            'stats' => [
                'total_executions' => count($this->executions),
                'successful' => $this->getSuccessCount(),
                'failed' => $this->getFailureCount(),
            ],
        ];
    }

    /**
     * Create a ParsedResponseDTO with no modifications.
     *
     * @param  string  $response  The original response
     */
    public static function unchanged(string $response): self
    {
        return new self(
            originalResponse: $response,
            modifiedResponse: $response,
            executions: [],
            actions: []
        );
    }

    /**
     * Create a ParsedResponseDTO with a single execution result.
     */
    public static function withExecution(
        string $originalResponse,
        string $modifiedResponse,
        ScriptExecutionResultDTO $execution
    ): self {
        return new self(
            originalResponse: $originalResponse,
            modifiedResponse: $modifiedResponse,
            executions: [$execution],
            actions: []
        );
    }
}
