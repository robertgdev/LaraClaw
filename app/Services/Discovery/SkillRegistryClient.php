<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Logging\MultiLogger;
use App\Services\SkillClassificationService;
use App\Services\SkillSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Client for the external skills registry CLI tool.
 *
 * Wraps `npx skills find` and `npx skills add` commands,
 * caching search results and triggering index refresh after installation.
 */
class SkillRegistryClient
{
    protected SkillSearchService $skillService;

    protected SkillClassificationService $classificationService;

    protected SkillsFindOutputParser $parser;

    public function __construct(
        SkillSearchService $skillService,
        SkillClassificationService $classificationService,
        ?SkillsFindOutputParser $parser = null
    ) {
        $this->skillService = $skillService;
        $this->classificationService = $classificationService;
        $this->parser = $parser ?? new SkillsFindOutputParser;
    }

    /**
     * Search for skills via `npx skills find`.
     *
     * Results are cached for 1 hour.
     *
     * @param  string  $searchTerm  The search term
     * @return array<array{name: string, description: string, owner: string, repo: string, installs?: int}>
     */
    public function find(string $searchTerm): array
    {
        $cacheKey = 'skills_find:'.md5($searchTerm);

        return Cache::remember($cacheKey, 3600, function () use ($searchTerm) {
            $result = Process::timeout(60)->run(['npx', 'skills', 'find', $searchTerm]);

            if (! $result->successful()) {
                MultiLogger::warning('npx skills find failed', [
                    'searchTerm' => $searchTerm,
                    'error' => $result->errorOutput(),
                ]);

                return [];
            }

            $output = trim($result->output());

            if (empty($output)) {
                return [];
            }

            return $this->parser->parse($output);
        });
    }

    /**
     * Install a skill using `npx skills add`.
     *
     * @param  string  $ownerRepoSkill  Install spec like "owner/repo@skillname"
     * @param  callable|null  $outputCallback  Optional callback for real-time output
     * @return bool True if installation succeeded
     */
    public function install(string $ownerRepoSkill, ?callable $outputCallback = null): bool
    {
        MultiLogger::info("Installing skill: {$ownerRepoSkill}");

        $result = Process::timeout(120)->run(
            ['npx', 'skills', 'add', '--yes', $ownerRepoSkill],
            function (string $type, string $output) use ($outputCallback): void {
                MultiLogger::debug("npx skills add: {$output}");

                if ($outputCallback !== null) {
                    $outputCallback($type, $output);
                }
            }
        );

        if (! $result->successful()) {
            MultiLogger::error('Failed to install skill', [
                'skill' => $ownerRepoSkill,
                'error' => $result->errorOutput(),
                'output' => $result->output(),
            ]);

            return false;
        }

        MultiLogger::info("Skill installed successfully: {$ownerRepoSkill}");

        $this->refreshSkillIndex();

        return true;
    }

    /**
     * Refresh the skill index after installation.
     */
    public function refreshSkillIndex(): void
    {
        try {
            $this->skillService->refreshIndex();
            $this->classificationService->classifyAllSkills(false);

            MultiLogger::info('Skill index refreshed after installation');
        } catch (\Exception $e) {
            MultiLogger::error('Failed to refresh skill index', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
