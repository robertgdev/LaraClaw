<?php

declare(strict_types=1);

namespace App\Services\ResponseParser;

use App\Logging\MultiLogger;
use App\Services\SkillSearchService;

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
     *
     * @param  string  $scriptPath  The script path (e.g., "scripts/schedule.sh")
     * @return array{skill: string, script: string}|null
     */
    public function extractScriptInfo(string $scriptPath): ?array
    {
        $scriptPath = str_replace('\\', '/', $scriptPath);
        $scriptPath = trim($scriptPath, '/');

        // Pattern 1: scripts/schedule.sh -> skill=schedule, script=schedule.sh
        if (preg_match('#^scripts/([^/]+)\.(\w+)$#', $scriptPath, $matches)) {
            $scriptName = $matches[1].'.'.$matches[2];
            $inferredSkill = $matches[1];
            $actualSkill = $this->findSkillForScript($inferredSkill, $scriptName);

            return [
                'skill' => $actualSkill ?? $inferredSkill,
                'script' => $scriptName,
            ];
        }

        // Pattern 2: schedule/scripts/schedule.sh -> skill=schedule, script=schedule.sh
        if (preg_match('#^([^/]+)/scripts/([^/]+\.(\w+))$#', $scriptPath, $matches)) {
            return [
                'skill' => $matches[1],
                'script' => $matches[2],
            ];
        }

        // Pattern 3: .agents/skills/schedule/scripts/schedule.sh
        if (preg_match('#\.agents/skills/([^/]+)/scripts/([^/]+\.(\w+))$#', $scriptPath, $matches)) {
            return [
                'skill' => $matches[1],
                'script' => $matches[2],
            ];
        }

        // Pattern 4: Just a script name like "schedule.sh" -> try to infer skill
        if (preg_match('#^([^/]+)\.(\w+)$#', $scriptPath, $matches)) {
            $scriptName = $matches[1].'.'.$matches[2];
            $inferredSkill = $matches[1];
            $actualSkill = $this->findSkillForScript($inferredSkill, $scriptName);

            return [
                'skill' => $actualSkill ?? $inferredSkill,
                'script' => $scriptName,
            ];
        }

        return null;
    }

    /**
     * Parse a command string into script path and arguments.
     *
     * @return array{script: string|null, args: array<string>}
     */
    public function parseCommand(string $command): array
    {
        $parts = $this->tokenizeCommand($command);

        if (empty($parts)) {
            return ['script' => null, 'args' => []];
        }

        $script = array_shift($parts);

        return [
            'script' => $script,
            'args' => $parts,
        ];
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
        if (empty($skills)) {
            MultiLogger::info('Skill index empty, refreshing...');
            $skills = $this->skillSearch->refreshIndex();
        }

        MultiLogger::debug('Finding skill for script', [
            'inferred_skill' => $inferredSkill,
            'script_name' => $scriptName,
            'available_skills' => array_keys($skills),
        ]);

        // Exact match
        if (isset($skills[$inferredSkill])) {
            return $inferredSkill;
        }

        // Normalized match (remove underscores, hyphens)
        $normalizedInferred = strtolower(str_replace(['_', '-'], '', $inferredSkill));

        foreach ($skills as $skillName => $skill) {
            $normalizedSkillName = strtolower(str_replace(['_', '-'], '', $skillName));

            if ($normalizedSkillName === $normalizedInferred) {
                MultiLogger::debug('Found normalized skill match', [
                    'skill' => $skillName,
                    'normalized' => $normalizedSkillName,
                ]);

                return $skillName;
            }

            // Check script list
            if (! empty($skill['has_scripts'])) {
                $scripts = $this->skillSearch->getSkillScripts($skillName);
                foreach ($scripts as $script) {
                    if ($script['name'] === $scriptName) {
                        MultiLogger::debug('Found skill by script lookup', [
                            'skill' => $skillName,
                            'script' => $scriptName,
                        ]);

                        return $skillName;
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
