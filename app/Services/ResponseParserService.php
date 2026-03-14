<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ExecuteRequestDTO;
use App\DTOs\ParsedResponseDTO;
use App\DTOs\ScriptExecutionResultDTO;
use App\DTOs\ScriptValidationDTO;
use App\Logging\MultiLogger;
use App\Services\ResponseParser\ExecuteBlockDetector;
use App\Services\ResponseParser\ScriptPathResolver;
use App\TypedCollections\ExecuteRequestDTOCollection;
use App\TypedCollections\ScriptValidationDTOCollection;

/**
 * Service for parsing AI responses and executing script commands.
 *
 * Delegates pattern detection to ExecuteBlockDetector and path
 * resolution to ScriptPathResolver. Orchestrates execution via
 * ScriptExecutionService and formats the results.
 *
 * Supported formats:
 * - ```execute: scripts/schedule.sh create --cron"0 9 * * *"```
 * - [execute: scripts/schedule.sh create --cron"0 9 * * *"]
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
     * Parse AI response and execute any script commands found.
     */
    public function parseAndExecute(string $response): ParsedResponseDTO
    {
        $finalResponse = trim($response);
        $executeResults = [];

        // Check if response contains execute blocks
        if (! $this->detector->hasExecuteBlocks($finalResponse)) {
            return new ParsedResponseDTO(
                originalResponse: $response,
                modifiedResponse: $finalResponse,
                executions: $executeResults,
            );
        }

        // Extract and process execute blocks
        $blocks = $this->detector->extractAll($finalResponse);

        foreach ($blocks as $block) {
            $command = $block['command'];
            $fullMatch = $block['full_match'];

            MultiLogger::debug('Processing execute block', ['command' => $command]);

            // Parse the command
            $parsed = $this->resolver->parseCommand($command);

            if (! $parsed->script) {
                MultiLogger::warning('No script found in command', ['command' => $command]);

                continue;
            }

            // Check if this is a script path or a direct command
            if ($this->resolver->isScriptPath($parsed->script)) {
                // Extract skill and script info
                $scriptInfo = $this->resolver->extractScriptInfo($parsed->script);

                if (! $scriptInfo) {
                    MultiLogger::warning('Could not resolve script path', ['script' => $parsed->script]);

                    continue;
                }

                // Execute the script
                $result = $this->scriptExecutor->execute(
                    $scriptInfo->skill,
                    $scriptInfo->script,
                    $parsed->args
                );
            } else {
                // Direct command execution
                $result = $this->scriptExecutor->executeDirectCommand($command);
            }

            $executeResults[] = $result;

            // Replace the execute block with the result
            if ($result->success) {
                $replacement = $result->output ?: "[Command executed successfully]";
            } else {
                $replacement = "[Command execution failed: {$result->error}]";
            }

            $finalResponse = str_replace($fullMatch, $replacement, $finalResponse);
        }

        return new ParsedResponseDTO(
            originalResponse: $response,
            modifiedResponse: trim($finalResponse),
            executions: $executeResults,
        );
    }

    /**
     * Check if response contains any execute blocks.
     */
    public function hasExecuteBlocks(string $response): bool
    {
        return $this->detector->hasExecuteBlocks($response);
    }

    /**
     * Extract all execute requests from a response without executing them.
     */
    public function extractExecuteRequests(string $response): ExecuteRequestDTOCollection
    {
        $blocks = $this->detector->extractAll($response);
        $requests = [];

        foreach ($blocks as $block) {
            $parsed = $this->resolver->parseCommand($block['command']);
            $requests[] = new ExecuteRequestDTO(
                command: $block['command'],
                script: $parsed->script,
                args: $parsed->args,
            );
        }

        return new ExecuteRequestDTOCollection($requests);
    }

    /**
     * Validate all execute requests in a response without executing them.
     */
    public function validateExecuteRequests(string $response): ScriptValidationDTOCollection
    {
        $requests = $this->extractExecuteRequests($response);
        $validations = [];

        foreach ($requests as $request) {
            $scriptInfo = $this->resolver->extractScriptInfo($request->script ?? '');

            if (! $scriptInfo) {
                $validations[] = new ScriptValidationDTO(
                    valid: false,
                    command: $request->script ?? '',
                    error: 'Could not determine skill from script path',
                );

                continue;
            }

            $validation = $this->scriptExecutor->validateScript(
                $scriptInfo->skill,
                $scriptInfo->script
            );

            $validations[] = new ScriptValidationDTO(
                valid: $validation['valid'],
                command: $request->script ?? '',
                error: $validation['error'] ?? null,
            );
        }

        return new ScriptValidationDTOCollection($validations);
    }
}
