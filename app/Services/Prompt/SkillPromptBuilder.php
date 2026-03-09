<?php

declare(strict_types=1);

namespace App\Services\Prompt;

use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;

/**
 * Builds the skills section of the system prompt.
 *
 * Tells the LLM what skills are available, how to invoke them,
 * and provides few-shot examples. Supports template-based rendering
 * with a hardcoded fallback for backwards compatibility.
 */
class SkillPromptBuilder
{
    protected ?SkillSearchService $skillService = null;

    /**
     * Custom template paths for testing purposes.
     */
    protected ?string $customStorageClawPath = null;

    protected ?string $customResourcesClawPath = null;

    /**
     * Set the skill service dependency.
     */
    public function setSkillService(SkillSearchService $skillService): void
    {
        $this->skillService = $skillService;
    }

    /**
     * Set custom template paths (for testing).
     */
    public function setTemplatePaths(?string $storageClawPath, ?string $resourcesClawPath): void
    {
        $this->customStorageClawPath = $storageClawPath;
        $this->customResourcesClawPath = $resourcesClawPath;
    }

    /**
     * Build the skills section for the system prompt.
     */
    public function build(): ?string
    {
        if ($this->skillService === null) {
            return null;
        }

        $skills = $this->skillService->getAllSkills();
        if (empty($skills)) {
            return null;
        }

        // Try loading template from user customizations first, then factory defaults
        $template = $this->loadTemplate('skill-instructions.md');

        // If no template found, fall back to hardcoded version
        if ($template === null) {
            return $this->buildHardcoded($skills);
        }

        // Generate the dynamic skills list
        $skillsList = $this->generateSkillsList($skills);

        // Replace the placeholder with the generated content
        return str_replace('{{skills_list}}', $skillsList, $template);
    }

    /**
     * Load a template file from user customizations or factory defaults.
     */
    protected function loadTemplate(string $filename): ?string
    {
        // Try user customizations first
        $userPath = $this->customStorageClawPath
            ? $this->customStorageClawPath.'/'.$filename
            : storage_path('app/claw/'.$filename);

        if (File::exists($userPath)) {
            $content = File::get($userPath);
            if ($content !== '') {
                return $content;
            }
        }

        // Fall back to factory defaults
        $factoryPath = $this->customResourcesClawPath
            ? $this->customResourcesClawPath.'/'.$filename
            : resource_path('claw/'.$filename);

        if (File::exists($factoryPath)) {
            $content = File::get($factoryPath);
            if ($content !== '') {
                return $content;
            }
        }

        return null;
    }

    /**
     * Generate the markdown list of available skills.
     */
    public function generateSkillsList(array $skills): string
    {
        $lines = [];

        foreach ($skills as $skillName => $skill) {
            $description = $skill['description'] ?? 'No description available';
            $hasScripts = $skill['has_scripts'] ?? false;

            $lines[] = "### {$skillName}";
            $lines[] = '';
            $lines[] = $description;

            if ($hasScripts && $this->skillService) {
                $scripts = $this->skillService->getSkillScripts($skillName);
                if (! empty($scripts)) {
                    $lines[] = '';
                    $lines[] = '**Available scripts:**';
                    foreach ($scripts as $script) {
                        $lines[] = "- `scripts/{$script['name']}`";
                    }
                }
            } else {
                $lines[] = '';
                $lines[] = '*This skill has no executable scripts. Use direct commands to interact with external services.*';
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Fallback method for building skills section when template is not available.
     */
    protected function buildHardcoded(array $skills): string
    {
        $lines = [
            '# Available Skills',
            '',
            'You have access to skills that can perform actions on the local system. ',
            'When a user asks you to do something that requires local execution (like generating images, ',
            'scheduling tasks, browsing the web, etc.), you MUST use the appropriate skill by outputting ',
            'an execute block in your response.',
            '',
            '## How to Invoke Skills',
            '',
            '### Skills with Scripts',
            '',
            'For skills that have executable scripts, output a code block with the `execute:` language identifier:',
            '',
            '```',
            '```execute: scripts/<script-name> <arguments>```',
            '```',
            '',
            'The system will detect this block, execute the script, and replace the block with the output. ',
            'You can then use the results in your response.',
            '',
            '### Skills without Scripts (Direct Commands)',
            '',
            'For skills that do NOT have executable scripts (documentation-only skills), you can output ',
            'a direct shell command. The command will be executed safely and the output returned to you.',
            '',
            '```',
            '```execute: curl "https://wttr.in/Belgrade?format=3"```',
            '```',
            '',
            '**Important:** Direct commands are validated against a security blocklist. Dangerous commands ',
            '(like `rm -rf /`, `sudo`, etc.) will be rejected.',
            '',
            '**CRITICAL RULES:**',
            '1. NEVER make up fake file paths or URLs - only use paths returned by script execution',
            '2. NEVER claim to have done something without actually invoking the skill',
            '3. ALWAYS use execute blocks for any action that requires local system access',
            '4. Wait for the script output before claiming success',
            '5. For skills without scripts, use direct shell commands (e.g., `curl`, `wget`, etc.)',
            '',
            '## Available Skills',
            '',
        ];

        foreach ($skills as $skillName => $skill) {
            $description = $skill['description'] ?? 'No description available';
            $hasScripts = $skill['has_scripts'] ?? false;

            $lines[] = "### {$skillName}";
            $lines[] = '';
            $lines[] = $description;

            if ($hasScripts && $this->skillService) {
                $scripts = $this->skillService->getSkillScripts($skillName);
                if (! empty($scripts)) {
                    $lines[] = '';
                    $lines[] = '**Available scripts:**';
                    foreach ($scripts as $script) {
                        $lines[] = "- `scripts/{$script['name']}`";
                    }
                }
            } else {
                $lines[] = '';
                $lines[] = '*This skill has no executable scripts. Use direct commands to interact with external services.*';
            }
            $lines[] = '';
        }

        $lines[] = '## Few-Shot Examples';
        $lines[] = '';
        $lines[] = '### Example 1: Image Generation (Skill with Script)';
        $lines[] = '';
        $lines[] = '**User:** Generate an image of a sunset over the ocean';
        $lines[] = '';
        $lines[] = "**Assistant:** I'll generate that image for you using the imagegen skill.";
        $lines[] = '';
        $lines[] = '```execute: scripts/image_gen.py generate --prompt "a beautiful sunset over the ocean with warm orange and pink colors" --out output/sunset.png```';
        $lines[] = '';
        $lines[] = '[After execution, the block is replaced with the output, e.g.:]';
        $lines[] = '> **Script: `image_gen.py`**';
        $lines[] = '> ✅ Image saved to: output/sunset.png';
        $lines[] = '';
        $lines[] = 'Your image has been generated and saved to `output/sunset.png`.';
        $lines[] = '';
        $lines[] = '### Example 2: Weather Check (Skill without Script)';
        $lines[] = '';
        $lines[] = '**User:** What\'s the weather in Belgrade?';
        $lines[] = '';
        $lines[] = "**Assistant:** I'll check the weather for you using a weather service.";
        $lines[] = '';
        $lines[] = '```execute: curl "wttr.in/Belgrade?format=3"```';
        $lines[] = '';
        $lines[] = '[After execution:]';
        $lines[] = '> **Command: `curl "wttr.in/Belgrade?format=3"`**';
        $lines[] = '> ✅ Belgrade: ⛅️ +15°C';
        $lines[] = '';
        $lines[] = 'The current weather in Belgrade is partly cloudy with a temperature of 15°C.';
        $lines[] = '';
        $lines[] = '### Example 3: Scheduling a Reminder';
        $lines[] = '';
        $lines[] = '**User:** Remind me about the team meeting every day at 9am';
        $lines[] = '';
        $lines[] = "**Assistant:** I'll schedule that reminder for you.";
        $lines[] = '';
        $lines[] = '```execute: scripts/schedule.sh create --cron "0 9 * * *" --message "Team meeting"```';
        $lines[] = '';
        $lines[] = '[After execution:]';
        $lines[] = '> **Script: `schedule.sh`**';
        $lines[] = '> ✅ Reminder created with ID: reminder-001';
        $lines[] = '';
        $lines[] = "Done! You'll receive a reminder about the team meeting every day at 9:00 AM.";
        $lines[] = '';
        $lines[] = '### Example 4: What NOT To Do';
        $lines[] = '';
        $lines[] = '**WRONG:** Making up fake paths:';
        $lines[] = "> I've generated your image! Here it is: `sandbox:/mnt/data/sunset.png`";
        $lines[] = "> ❌ This is WRONG - the path doesn't exist and no skill was invoked";
        $lines[] = '';
        $lines[] = '**CORRECT:** Using an execute block:';
        $lines[] = '> ```execute: scripts/image_gen.py generate --prompt "..."```';
        $lines[] = '> ✅ This is CORRECT - the skill is invoked and returns a real path';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = "**Remember:** When in doubt, use an execute block. It's better to invoke a skill and get an error than to make up fake results.";

        return implode("\n", $lines);
    }
}
