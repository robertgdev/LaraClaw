<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\DTOs\SymlinkResultDTO;
use App\DTOs\TemplateCopyResultDTO;
use App\DTOs\WorkspaceInitResultDTO;
use Illuminate\Support\Facades\File;

use function Safe\exec;
use function Safe\symlink;

/**
 * Handles workspace directory creation, template copying, and symlinks.
 *
 * Extracted from LaraClawSetupCommand to be reusable across
 * setup and update/upgrade commands.
 */
class SetupWorkspaceInitializer
{
    /**
     * Create all workspace directories for agents.
     *
     * @param  string  $workspacePath  Root workspace path
     * @param  string  $defaultAgentId  Default agent ID
     * @param  array<int, array{agent_id: string}>  $additionalAgents  Additional agents
     */
    public function createDirectories(string $workspacePath, string $defaultAgentId, array $additionalAgents = []): WorkspaceInitResultDTO
    {
        $messages = [];

        // Workspace directory
        if (! File::isDirectory($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true);
            $messages[] = "Created workspace: {$workspacePath}";
        }

        // Default agent directory
        $defaultAgentDir = $workspacePath.'/'.$defaultAgentId;
        if (! File::isDirectory($defaultAgentDir)) {
            File::makeDirectory($defaultAgentDir, 0755, true);
            $messages[] = "Created agent directory: {$defaultAgentDir}";
        }

        // Copy templates to default agent
        $messages = array_merge($messages, $this->copyAgentTemplates($defaultAgentDir, $defaultAgentId)->messages);

        // Additional agent directories
        foreach ($additionalAgents as $agent) {
            $agentDir = $workspacePath.'/'.$agent['agent_id'];
            if (! File::isDirectory($agentDir)) {
                File::makeDirectory($agentDir, 0755, true);
                $messages[] = "Created agent directory: {$agentDir}";
            }
            $messages = array_merge($messages, $this->copyAgentTemplates($agentDir, $agent['agent_id'])->messages);
        }

        // Files directory
        $filesDir = $workspacePath.'/files';
        if (! File::isDirectory($filesDir)) {
            File::makeDirectory($filesDir, 0755, true);
            $messages[] = "Created files directory: {$filesDir}";
        }

        return new WorkspaceInitResultDTO(messages: $messages);
    }

    /**
     * Copy template files to an agent's working directory.
     */
    public function copyAgentTemplates(string $agentDir, string $agentId): WorkspaceInitResultDTO
    {
        $templatesDir = $this->getTemplatesDir();
        $messages = [];

        // Copy AGENTS.md
        $agentsMdSource = $templatesDir.'/AGENTS.md';
        if (File::exists($agentsMdSource)) {
            File::copy($agentsMdSource, $agentDir.'/AGENTS.md');
            $messages[] = "Copied AGENTS.md to {$agentDir}/";
        }

        // Copy .claude directory
        $claudeDirSource = $templatesDir.'/.claude';
        if (File::isDirectory($claudeDirSource) && File::allFiles($claudeDirSource)) {
            File::copyDirectory($claudeDirSource, $agentDir.'/.claude');
            $messages[] = "Copied .claude/ to {$agentDir}/";
        } else {
            File::ensureDirectoryExists($agentDir.'/.claude');
        }

        // Copy heartbeat.md
        $heartbeatSource = $templatesDir.'/heartbeat.md';
        if (File::exists($heartbeatSource)) {
            File::copy($heartbeatSource, $agentDir.'/heartbeat.md');
            $messages[] = "Copied heartbeat.md to {$agentDir}/";
        }

        // Copy SOUL.md to .laraclaw directory
        $soulSource = $templatesDir.'/SOUL.md';
        if (File::exists($soulSource)) {
            $laraclawDir = $agentDir.'/.laraclaw';
            if (! File::isDirectory($laraclawDir)) {
                File::makeDirectory($laraclawDir, 0755, true);
            }
            File::copy($soulSource, $laraclawDir.'/SOUL.md');
            $messages[] = "Copied SOUL.md to {$laraclawDir}/";
        }

        // Copy AGENTS.md to .claude/CLAUDE.md for Claude CLI
        if (File::exists($agentsMdSource)) {
            $claudeDir = $agentDir.'/.claude';
            if (! File::isDirectory($claudeDir)) {
                File::makeDirectory($claudeDir, 0755, true);
            }
            File::copy($agentsMdSource, $claudeDir.'/CLAUDE.md');
            $messages[] = "Copied CLAUDE.md to {$claudeDir}/";
        }

        // Link .agents/skills directory
        $skillsSource = $templatesDir.'/.agents/skills';
        if (File::isDirectory($skillsSource)) {
            $agentAgentsDir = $agentDir.'/.agents';
            if (! File::isDirectory($agentAgentsDir)) {
                File::makeDirectory($agentAgentsDir, 0755, true);
            }

            $skillsTarget = $agentAgentsDir.'/skills';
            if (! File::exists($skillsTarget) && ! is_link($skillsTarget)) {
                symlink($skillsSource, $skillsTarget);
                $messages[] = "Linked skills to {$skillsTarget}";
            }

            $claudeSkillsTarget = $agentDir.'/.claude/skills';
            if (! File::exists($claudeSkillsTarget) && ! is_link($claudeSkillsTarget)) {
                symlink($skillsSource, $claudeSkillsTarget);
                $messages[] = "Linked skills to {$claudeSkillsTarget}";
            }
        }

        return new WorkspaceInitResultDTO(messages: $messages);
    }

    /**
     * Copy template files from resources/laraclaw to the workspace.
     */
    public function copyTemplateFilesToStorage(string $workspaceName): TemplateCopyResultDTO
    {
        $sourceDir = resource_path('laraclaw');
        $targetDir = storage_path("app/{$workspaceName}");
        $messages = [];
        $copied = 0;
        $skipped = 0;

        if (! File::isDirectory($sourceDir)) {
            return new TemplateCopyResultDTO(
                copied: 0,
                skipped: 0,
                messages: ['No template files found in resources/laraclaw - skipping.']
            );
        }

        $files = File::allFiles($sourceDir);
        if (empty($files)) {
            return new TemplateCopyResultDTO(
                copied: 0,
                skipped: 0,
                messages: ['No template files found in resources/laraclaw - skipping.']
            );
        }

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $targetPath = $targetDir.'/'.$relativePath;

            if (File::exists($targetPath)) {
                $skipped++;

                continue;
            }

            $targetFileDir = dirname($targetPath);
            if (! File::isDirectory($targetFileDir)) {
                File::makeDirectory($targetFileDir, 0755, true);
            }

            File::copy($file->getPathname(), $targetPath);
            $messages[] = "Copied {$relativePath}";
            $copied++;
        }

        return new TemplateCopyResultDTO(copied: $copied, skipped: $skipped, messages: $messages);
    }

    /**
     * Create a symlink from .agents to storage/app/{workspace}/agents.
     */
    public function createAgentsSymlink(string $workspaceName): SymlinkResultDTO
    {
        $targetLink = base_path('.agents');
        $agentsSource = storage_path("app/$workspaceName/agents");

        if (! File::isDirectory($agentsSource)) {
            return SymlinkResultDTO::skipped('No agents directory found - skipping symlink.');
        }

        if (File::exists($targetLink) || is_link($targetLink)) {
            return SymlinkResultDTO::skipped('Agents symlink already exists at .agents');
        }

        $created = $this->createCrossPlatformSymlink($agentsSource, $targetLink);

        if ($created) {
            return SymlinkResultDTO::created("Created symlink: .agents → storage/app/$workspaceName/agents/");
        }

        return SymlinkResultDTO::skipped('Could not create agents symlink. May need elevated privileges.');
    }

    /**
     * Create a symlink in a cross-platform manner.
     */
    public function createCrossPlatformSymlink(string $source, string $target): bool
    {
        $parentDir = dirname($target);
        if (! File::isDirectory($parentDir)) {
            File::makeDirectory($parentDir, 0755, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $source = str_replace('/', '\\', $source);
            $target = str_replace('/', '\\', $target);
            $command = sprintf('mklink /J "%s" "%s"', $target, $source);
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            return $returnCode === 0;
        }

        try {
            symlink($source, $target);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the templates directory path.
     */
    protected function getTemplatesDir(): string
    {
        return resource_path('laraclaw/template');
    }
}
