<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Logging\MultiLogger;
use App\Services\ScriptExecutionService;
use App\Services\SkillSearchService;

use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Handles direct skill execution without LLM invocation.
 *
 * When a high-confidence skill match is found, this handler
 * attempts to execute the skill's script directly, bypassing
 * the LLM for faster response times.
 */
class SkillExecutionHandler
{
    public function __construct(
        protected ScriptExecutionService $scriptExecutor,
        protected SkillSearchService $skillService
    ) {}

    /**
     * Try to execute a skill directly without LLM invocation.
     *
     * @param  string  $skillName  The name of the skill to execute
     * @param  string  $message  The user message
     * @param  string  $agentId  The agent ID for context
     * @return string|null The execution result, or null if execution failed
     */
    public function tryDirectExecution(
        string $skillName,
        string $message,
        string $agentId,
    ): ?string {
        try {
            // Get skill info
            $skill = $this->skillService->getSkill($skillName);
            if (! $skill) {
                MultiLogger::warning("Skill not found for direct execution: {$skillName}");

                return null;
            }

            // Check if skill has scripts
            $scripts = $this->skillService->getSkillScripts($skillName);
            if ($scripts->isEmpty()) {
                MultiLogger::debug("Skill has no scripts for direct execution: {$skillName}");

                return null;
            }

            // Get the primary script (first script or one named after the skill)
            $primaryScript = null;
            foreach ($scripts as $script) {
                $scriptName = $script->name;
                $skillBaseName = basename($skillName);
                if (str_starts_with($scriptName, $skillBaseName)) {
                    $primaryScript = $script;
                    break;
                }
            }

            // Fallback to first script
            if (! $primaryScript && $scripts->isNotEmpty()) {
                $primaryScript = $scripts->first();
            }

            if (! $primaryScript) {
                MultiLogger::debug("No suitable script found for skill: {$skillName}");

                return null;
            }

            // Extract arguments from message
            $args = $this->extractArgsFromMessage($message, $skillName);

            MultiLogger::info('Attempting direct skill execution', [
                'skill' => $skillName,
                'script' => $primaryScript->name,
                'args' => $args,
            ]);

            // Execute the script
            $result = $this->scriptExecutor->execute(
                skillName: $skillName,
                scriptName: $primaryScript->name,
                args: $args,
                agentId: $agentId
            );

            if ($result->success) {
                $output = trim($result->output);

                return ! empty($output) ? $output : '✅ Skill executed successfully.';
            }

            MultiLogger::warning('Direct skill execution failed', [
                'skill' => $skillName,
                'error' => $result->error,
            ]);

            return null;

        } catch (\Exception $e) {
            MultiLogger::error("Direct skill execution error: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Extract arguments from a user message for skill execution.
     *
     * @param  string  $message  The user message
     * @param  string  $skillName  The skill name for context
     * @return array<string> Extracted arguments
     */
    public function extractArgsFromMessage(string $message, string $skillName): array
    {
        // For imagegen skill, extract the prompt
        if (str_contains(strtolower($skillName), 'image')) {
            $prompt = preg_replace('/^(generate|create|make|draw)\s+(an?\s+)?(image|picture|photo|illustration)\s*(of|with)?\s*/i', '', $message);
            $prompt = trim($prompt);

            if (! empty($prompt)) {
                return ['generate', '--prompt', $prompt];
            }
        }

        // For schedule skill, look for time patterns
        if (str_contains(strtolower($skillName), 'schedule')) {
            if (preg_match('/(\d{1,2}:\d{2}|\d{1,2}\s*(am|pm))/i', $message, $matches)) {
                return ['create', '--time', $matches[1]];
            }
        }

        // Default: pass the message as context
        return ['--message', $message];
    }
}
