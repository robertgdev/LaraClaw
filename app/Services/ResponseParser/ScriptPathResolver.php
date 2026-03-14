<?php

declare(strict_types=1);

namespace App\Services\ResponseParser;

use App\DTOs\ParsedCommandDTO;
use App\DTOs\ScriptInfoDTO;
use App\DTOs\SkillDTO;
use App\Logging\MultiLogger;
use App\Services\SkillSearchService;
use App\TypedCollections\SkillDTOCollection;

use function Safe\preg_match;

/**
 * Resolves script paths from AI execute commands to skill/script pairs.
 *
 * Handles multiple path formats:
 * - scripts/schedule.sh
 * - schedule/scripts/schedule.sh
 * - .agents/skills/schedule/scripts/schedule.sh
 * - schedule.sh (bare script name)
 *
 * Also provides skill-name fuzzy matching when exact matches fail.
 */
class ScriptPathResolver
{
    protected SkillSearchService $skillSearch;

    public function __construct(SkillSearchService $skillSearch)
    {
        $this->skillSearch = $skillSearch;
    }

    /**
     * Check if a command is a script path (starts with scripts/ or matches skill path patterns).
     */
    public function isScriptPath(string $command): bool
    {
        $trimmed = trim($command);

        if (str_starts_with($trimmed, 'scripts/')) {
            return true;
        }

        if (preg_match('#^[^/]+/scripts/#', $trimmed)) {
            return true;
        }

        if (str_contains($trimmed, '.agents/skills/') && str_contains($trimmed, '/scripts/')) {
            return true;
        }

        return false;
    }

    /**
     * Extract skill name and script name from a script path.
     */
    public function extractScriptInfo(string $scriptPath): ?ScriptInfoDTO
    {
        $scriptPath = str_replace('\\', '/', $scriptPath);
        $scriptPath = trim($scriptPath, '/');

        // Pattern 1: scripts/schedule.sh -> skill=schedule, script=schedule.sh
        if (preg_match('#^scripts/([^/]+)\.(\w+)$#', $scriptPath, $matches)) {
            $scriptName = $matches[1].'.'.$matches[2];
            $inferredSkill = $matches[1];
            $actualSkill = $this->findSkillForScript($inferredSkill, $scriptName);

            return new ScriptInfoDTO(
                skill: $actualSkill ?? $inferredSkill,
                script: $scriptName,
            );
        }

        // Pattern 2: schedule/scripts/schedule.sh -> skill=schedule, script=schedule.sh
        if (preg_match('#^([^/]+)/scripts/([^/]+\.(\w+))$#', $scriptPath, $matches)) {
            return new ScriptInfoDTO(
                skill: $matches[1],
                script: $matches[2],
            );
        }

        // Pattern 3: .agents/skills/schedule/scripts/schedule.sh
        if (preg_match('#\.agents/skills/([^/]+)/scripts/([^/]+\.(\w+))$#', $scriptPath, $matches)) {
            return new ScriptInfoDTO(
                skill: $matches[1],
                script: $matches[2],
            );
        }

        // Pattern 4: Just a script name like "schedule.sh" -> try to infer skill
        if (preg_match('#^([^/]+)\.(\w+)$#', $scriptPath, $matches)) {
            $scriptName = $matches[1].'.'.$matches[2];
            $inferredSkill = $matches[1];
            $actualSkill = $this->findSkillForScript($inferredSkill, $scriptName);

            return new ScriptInfoDTO(
                skill: $actualSkill ?? $inferredSkill,
                script: $scriptName,
            );
        }

        return null;
    }

    /**
     * Parse a command string into script path and arguments.
     */
    public function parseCommand(string $command): ParsedCommandDTO
    {
        $parts = $this->tokenizeCommand($command);

        if (empty($parts)) {
            return new ParsedCommandDTO(
                script: null,
                args: [],
            );
        }

        $script = array_shift($parts);

        return new ParsedCommandDTO(
            script: $script,
            args: $parts,
        );
    }

    /**
     * Tokenize a command string, respecting quotes.
     *
     * @return array<string>
     */
    public function tokenizeCommand(string $command): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($inQuote) {
                if ($char === $quoteChar) {
                    $inQuote = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"' || $char === "'") {
                $inQuote = true;
                $quoteChar = $char;
            } elseif ($char === ' ' || $char === "\t" || $char === "\n") {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Try to find a skill that contains the given script.
     *
     * Handles cases where the script name doesn't match the skill name
     * (e.g., image_gen.py in the imagegen skill).
     */
    public function findSkillForScript(string $inferredSkill, string $scriptName): ?string
    {
        $skills = $this->skillSearch->getAllSkills();
        if ($skills->isEmpty()) {
            MultiLogger::info('Skill index empty, refreshing...');
            $parsedSkills = $this->skillSearch->refreshIndex();
            $skillDTOs = array_map(fn ($parsed) => $parsed->toSkillDTO(), array_values($parsedSkills));
            $skills = new SkillDTOCollection($skillDTOs);
        }

        MultiLogger::debug('Finding skill for script', [
            'inferred_skill' => $inferredSkill,
            'script_name' => $scriptName,
            'available_skills' => $skills->map(fn (SkillDTO $s) => $s->name)->all(),
        ]);

        // Exact match
        $exactMatch = $skills->first(fn (SkillDTO $s) => $s->name === $inferredSkill);
        if ($exactMatch) {
            return $inferredSkill;
        }

        // Normalized match (remove underscores, hyphens)
        $normalizedInferred = strtolower(str_replace(['_', '-'], '', $inferredSkill));

        foreach ($skills as $skill) {
            $normalizedSkillName = strtolower(str_replace(['_', '-'], '', $skill->name));

            if ($normalizedSkillName === $normalizedInferred) {
                MultiLogger::debug('Found normalized skill match', [
                    'skill' => $skill->name,
                    'normalized' => $normalizedSkillName,
                ]);

                return $skill->name;
            }

            // Check script list
            if ($skill->hasScripts) {
                $scripts = $this->skillSearch->getSkillScripts($skill->name);
                foreach ($scripts as $script) {
                    if ($script->name === $scriptName) {
                        MultiLogger::debug('Found skill by script lookup', [
                            'skill' => $skill->name,
                            'script' => $scriptName,
                        ]);

                        return $skill->name;
                    }
                }
            }
        }

        MultiLogger::warning('No skill found for script', [
            'inferred_skill' => $inferredSkill,
            'script_name' => $scriptName,
        ]);

        return null;
    }
}
