<?php

declare(strict_types=1);

namespace App\Services\ScriptExecution;

use App\DTOs\SkillDTO;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\File;

use function Safe\realpath;

/**
 * Validates scripts before execution.
 *
 * Handles path resolution, extension whitelisting, directory
 * whitelisting, and directory traversal prevention.
 */
class ScriptValidator
{
    protected SkillSearchService $skillSearch;

    /** @var array<int, string> */
    protected array $defaultAllowedExtensions = ['sh', 'py', 'ts', 'js'];

    /** @var array<int, string> */
    protected array $allowedExtensions;

    /**
     * @param  array<int, string>|null  $configAllowedExtensions
     */
    public function __construct(SkillSearchService $skillSearch, ?array $configAllowedExtensions = null)
    {
        $this->skillSearch = $skillSearch;
        $this->allowedExtensions = $configAllowedExtensions ?? $this->defaultAllowedExtensions;
    }

    /**
     * Resolve the full path to a script.
     *
     * @param  SkillDTO|array<string, mixed>  $skill  The skill data from SkillSearchService
     * @param  string  $scriptName  The script filename
     * @return string|null The full path or null if not found
     */
    public function resolveScriptPath(SkillDTO|array $skill, string $scriptName): ?string
    {
        // Security: Prevent directory traversal
        if (str_contains($scriptName, '..') || str_contains($scriptName, '/')) {
            return null;
        }

        $directory = $skill instanceof SkillDTO ? $skill->directory : $skill['directory'];
        $scriptsDir = $directory.'/scripts';
        $scriptPath = $scriptsDir.'/'.$scriptName;

        if (! File::exists($scriptPath)) {
            return null;
        }

        return realpath($scriptPath);
    }

    /**
     * Check if a script's extension is allowed.
     */
    public function isExtensionAllowed(string $scriptPath): bool
    {
        $extension = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));

        return in_array($extension, $this->allowedExtensions, true);
    }

    /**
     * Check if a script is in an allowed skill directory.
     */
    public function isScriptInAllowedDir(string $scriptPath): bool
    {
        $skillsDirs = $this->skillSearch->getSkillsDirs();
        $allowedDirs = array_column($skillsDirs, 'path');

        foreach ($allowedDirs as $dir) {
            $realDir = realpath($dir);
            if ($realDir && str_starts_with($scriptPath, $realDir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the list of allowed script extensions.
     *
     * @return array<string>
     */
    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensions;
    }

    /**
     * Validate a script path without executing it.
     *
     * @return array{valid: bool, error: string|null, path: string|null}
     */
    public function validate(string $skillName, string $scriptName, bool $executionEnabled = true): array
    {
        if (! $executionEnabled) {
            return [
                'valid' => false,
                'error' => 'Script execution is disabled',
                'path' => null,
            ];
        }

        $skill = $this->skillSearch->getSkill($skillName);
        if (! $skill) {
            return [
                'valid' => false,
                'error' => "Skill not found: {$skillName}",
                'path' => null,
            ];
        }

        $scriptPath = $this->resolveScriptPath($skill, $scriptName);
        if (! $scriptPath) {
            return [
                'valid' => false,
                'error' => "Script not found: {$scriptName}",
                'path' => null,
            ];
        }

        if (! $this->isExtensionAllowed($scriptPath)) {
            $extension = pathinfo($scriptPath, PATHINFO_EXTENSION);

            return [
                'valid' => false,
                'error' => "Extension '.{$extension}' not allowed",
                'path' => $scriptPath,
            ];
        }

        if (! $this->isScriptInAllowedDir($scriptPath)) {
            return [
                'valid' => false,
                'error' => 'Script not in allowed directory',
                'path' => $scriptPath,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'path' => $scriptPath,
        ];
    }
}
