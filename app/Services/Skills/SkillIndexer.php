<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Logging\MultiLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Indexes skills from filesystem directories and manages the in-memory cache.
 *
 * Scans configured skill directories for SKILL.md files, delegates parsing
 * to SkillFileParser, and stores the result in Laravel Cache for fast access.
 */
class SkillIndexer
{
    protected SettingsService $settings;

    protected SkillFileParser $parser;

    protected array $skillsDirs = [];

    protected string $cacheKey = 'laraclaw_skills_index';

    protected int $cacheTTL = 3600; // 1 hour

    public function __construct(SettingsService $settings, ?SkillFileParser $parser = null)
    {
        $this->settings = $settings;
        $this->parser = $parser ?? new SkillFileParser;
        $this->skillsDirs = $this->resolveSkillsDirs();
    }

    /**
     * Resolve all skills directories.
     *
     * @return array<array{type: string, path: string}>
     */
    public function resolveSkillsDirs(): array
    {
        $dirs = [];

        $skillsDir = base_path().'/.agents/skills';
        if (File::isDirectory($skillsDir)) {
            $dirs[] = ['type' => 'default', 'path' => $skillsDir];
        }

        MultiLogger::debug('Resolved skills directories', [
            'directories' => array_map(fn ($d) => $d['type'].': '.$d['path'], $dirs),
        ]);

        return $dirs;
    }

    /**
     * Index all skills from all directories (respecting priority order).
     *
     * @return array<string, array> Keyed by skill name
     */
    public function indexSkills(): array
    {
        $cached = Cache::get($this->cacheKey);
        if ($cached) {
            return $cached;
        }

        $index = [];

        foreach ($this->skillsDirs as $dirInfo) {
            $path = $dirInfo['path'];

            if (! File::isDirectory($path)) {
                continue;
            }

            $directories = File::directories($path);

            foreach ($directories as $dir) {
                $skillFile = $dir.'/SKILL.md';

                if (! File::exists($skillFile)) {
                    continue;
                }

                $skill = $this->parser->parse($skillFile);
                if ($skill) {
                    $index[$skill['name']] = $skill;
                }
            }
        }

        Cache::put($this->cacheKey, $index, $this->cacheTTL);

        MultiLogger::info('Indexed '.count($index).' skills');

        return $index;
    }

    /**
     * Refresh the skill index (clear cache and reindex).
     */
    public function refreshIndex(): array
    {
        Cache::forget($this->cacheKey);

        return $this->indexSkills();
    }

    /**
     * Get skills directories.
     *
     * @return array<array{type: string, path: string}>
     */
    public function getSkillsDirs(): array
    {
        return $this->skillsDirs;
    }

    /**
     * Get the primary skills directory path.
     */
    public function getSkillsDir(): string
    {
        if (! empty($this->skillsDirs)) {
            return $this->skillsDirs[0]['path'];
        }

        return $this->settings->getWorkspacePath().'/.agents/skills';
    }

    /**
     * Get the cache key (for testing).
     */
    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }
}
