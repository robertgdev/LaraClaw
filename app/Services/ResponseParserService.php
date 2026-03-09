<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ParsedResponse;
use App\DTOs\ScriptExecutionResult;
use App\Logging\MultiLogger;
use App\Services\ResponseParser\ExecuteBlockDetector;
use App\Services\ResponseParser\ScriptPathResolver;

/**
 * Service for parsing AI responses and executing script commands.
 *
 * Delegates pattern detection to ExecuteBlockDetector and path
 * resolution to ScriptPathResolver. Orchestrates execution via
 * ScriptExecutionService and formats the results.
 *
 * Supported formats:
 * - ```execute: scripts/schedule.sh create --cron "0 9 * * *"```
 * - [execute: scripts/schedule.sh create --cron "0 9 * * *"]
 */
class ResponseParserService
{
    protected ScriptExecutionService $scriptExecutor;

    protected ExecuteBlockDetector $detector;

    protected ScriptPathResolver $resolver;

    /**
     * Create a new ResponseParser instance.
     */
    public function __construct(ScriptExecutionService $scriptExecutor)
    {
        $this->scriptExecutor = $scriptExecutor;
        $this->detector = new ExecuteBlockDetector;
        $this->resolver = new ScriptPathResolver($scriptExecutor->getSkillSearch());
    }

    /**
     * Parse AI response and execute any script requests.
     *
     * @param  string  $response  The AI response to parse
     * @param  string|null  $agentId  The agent context for working directory
     * @return ParsedResponse The parsed response with execution results
     */
    public function parseAndExecute(
        string $response,
        ?string $agentId = null
    ): ParsedResponse {
        MultiLogger::debug('ResponseParser::parseAndExecute called', [
            'response_length' => strlen($response),
            'agent_id' => $agentId,
            'has_execute_block' => $this->hasExecuteRequests($response),
        ]);

        $executions = [];
        $actions = [];

        $modifiedResponse = $this->detector->replaceAll(
            $response,
            function (string $command, string $format) use ($agentId, &$executions, &$actions): string {
                if ($format === 'bare') {
                    MultiLogger::info('Detected bare execute request (missing code block formatting)', [
                        'command' => $command,
                    ]);
                }

                return $this->handleExecuteRequest($command, $agentId, $executions, $actions);
            }
        );

        return new ParsedResponse(
            originalResponse: $response,
            modifiedResponse: $modifiedResponse,
            executions: $executions,
            actions: $actions
        );
    }

    /**
     * Handle a single execute request.
     *
     * @param  string  $command  The command string from the AI
     * @param  string|null  $agentId  The agent context
     * @param  array  $executions  Reference to executions array
     * @param  array  $actions  Reference to actions array
     * @return string The replacement text for the execute block
     */
    protected function handleExecuteRequest(
        string $command,
        ?string $agentId,
        array &$executions,
        array &$actions
    ): string {
        MultiLogger::debug('Processing execute request', ['command' => $command]);

        // Direct command (doesn't start with scripts/)
        if (! $this->resolver->isScriptPath($command)) {
            MultiLogger::info('Routing to direct command handler', ['command' => $command]);

            return $this->handleDirectCommand($command, $agentId, $executions, $actions);
        }

        // Parse the command
        $parsed = $this->resolver->parseCommand($command);

        if (empty($parsed['script'])) {
            $executions[] = ScriptExecutionResult::error("Invalid command format: {$command}");
            $actions[] = [
                'type' => 'execute',
                'success' => false,
                'error' => 'Invalid command format',
            ];

            return '**Error:** Invalid command format. Expected: `script_path [args...]`';
        }

        $scriptPath = $parsed['script'];
        $args = $parsed['args'];

        // Extract skill/script info
        $scriptInfo = $this->resolver->extractScriptInfo($scriptPath);

        if (! $scriptInfo) {
            $executions[] = ScriptExecutionResult::error(
                "Could not determine skill from script path: {$scriptPath}"
            );
            $actions[] = [
                'type' => 'execute',
                'success' => false,
                'error' => 'Could not determine skill',
                'script' => $scriptPath,
            ];

            return "**Error:** Could not determine skill from script path: `{$scriptPath}`";
        }

        // Execute the script
        $result = $this->scriptExecutor->execute(
            skillName: $scriptInfo['skill'],
            scriptName: $scriptInfo['script'],
            args: $args,
            agentId: $agentId
        );

        $executions[] = $result;
        $actions[] = [
            'type' => 'execute',
            'success' => $result->success,
            'skill' => $scriptInfo['skill'],
            'script' => $scriptInfo['script'],
            'args' => $args,
        ];

        MultiLogger::info('Script execution result', [
            'skill' => $scriptInfo['skill'],
            'script' => $scriptInfo['script'],
            'success' => $result->success,
            'duration' => $result->duration,
        ]);

        return $this->formatExecutionResult($result, "Script: `{$scriptInfo['script']}`");
    }

    /**
     * Handle a direct command execution request.
     */
    protected function handleDirectCommand(
        string $command,
        ?string $agentId,
        array &$executions,
        array &$actions
    ): string {
        MultiLogger::debug('Processing direct command', ['command' => $command]);

        $result = $this->scriptExecutor->executeDirectCommand($command);

        $executions[] = $result;
        $actions[] = [
            'type' => 'execute_direct',
            'success' => $result->success,
            'command' => $command,
        ];

        MultiLogger::info('Direct command execution result', [
            'command' => $command,
            'success' => $result->success,
            'duration' => $result->duration,
        ]);

        $displayCommand = strlen($command) > 60
            ? substr($command, 0, 60).'...'
            : $command;

        return $this->formatExecutionResult($result, "Command: `{$displayCommand}`");
    }

    /**
     * Format an execution result for display.
     *
     * Unified formatter used for both script and direct command results.
     *
     * @param  ScriptExecutionResult  $result  The execution result
     * @param  string  $headerLabel  The label for the header (e.g., "Script: `schedule.sh`")
     * @return string Formatted result string
     */
    protected function formatExecutionResult(ScriptExecutionResult $result, string $headerLabel): string
    {
        $header = "**{$headerLabel}**";

        if ($result->success) {
            $output = trim($result->output);

            if (empty($output)) {
                return "{$header}\n\n✅ Executed successfully (no output)";
            }

            if (str_contains($output, "\n") || str_contains($output, '`')) {
                return "{$header}\n\n```\n{$output}\n```";
            }

            return "{$header}\n\n✅ {$output}";
        }

        $error = $result->error;
        $output = trim($result->output);

        $message = "{$header}\n\n❌ **Error:** {$error}";

        if (! empty($output)) {
            $message .= "\n\n**Output:**\n```\n{$output}\n```";
        }

        return $message;
    }

    /**
     * Check if a response contains any execute requests.
     */
    public function hasExecuteRequests(string $response): bool
    {
        $hasAny = $this->detector->hasExecuteRequests($response);

        if (! $hasAny) {
            $details = $this->detector->getDetectionDetails($response);
            MultiLogger::debug('hasExecuteRequests: no matches found', [
                'response_preview' => substr($response, 0, 500),
                ...$details,
            ]);
        }

        return $hasAny;
    }

    /**
     * Extract all execute requests from a response without executing them.
     *
     * @return array<int, array{command: string, script: string|null, args: array}>
     */
    public function extractExecuteRequests(string $response): array
    {
        $blocks = $this->detector->extractAll($response);
        $requests = [];

        foreach ($blocks as $block) {
            $parsed = $this->resolver->parseCommand($block['command']);
            $requests[] = [
                'command' => $block['command'],
                'script' => $parsed['script'],
                'args' => $parsed['args'],
            ];
        }

        return $requests;
    }

    /**
     * Validate all execute requests in a response without executing them.
     *
     * @return array<int, array{valid: bool, command: string, error: string|null}>
     */
    public function validateExecuteRequests(string $response): array
    {
        $requests = $this->extractExecuteRequests($response);
        $validations = [];

        foreach ($requests as $request) {
            $scriptInfo = $this->resolver->extractScriptInfo($request['script'] ?? '');

            if (! $scriptInfo) {
                $validations[] = [
                    'valid' => false,
                    'command' => $request['command'],
                    'error' => 'Could not determine skill from script path',
                ];

                continue;
            }

            $validation = $this->scriptExecutor->validateScript(
                $scriptInfo['skill'],
                $scriptInfo['script']
            );

            $validations[] = [
                'valid' => $validation['valid'],
                'command' => $request['command'],
                'error' => $validation['error'],
            ];
        }

        return $validations;
    }
}
