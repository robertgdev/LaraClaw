<?php

namespace App\Services;

use App\DTOs\SkillMatchStatisticsDTO;
use App\Services\Skills\SkillFileParser;
use App\Services\Skills\SkillIndexer;
use App\Services\Skills\SkillMatchCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Skill search and matching service.
 *
 * Delegates indexing to SkillIndexer, file parsing to SkillFileParser,
 * and database caching to SkillMatchCache. This class focuses on
 * search/matching logic and result ranking.
 */
class SkillSearchService
{
    protected SettingsService $settings;

    protected SkillIndexer $indexer;

    protected SkillFileParser $parser;

    protected SkillMatchCache $matchCache;

    protected array $skillIndex = [];

    public function __construct(SettingsService $settings, ?SkillIndexer $indexer = null, ?SkillFileParser $parser = null, ?SkillMatchCache $matchCache = null)
    {
        $this->settings = $settings;
        $this->parser = $parser ?? new SkillFileParser;
        $this->indexer = $indexer ?? new SkillIndexer($settings, $this->parser);
        $this->matchCache = $matchCache ?? new SkillMatchCache;
    }

    /**
     * Index all skills from all directories (respecting priority order).
     */
    public function indexSkills(): array
    {
        $this->skillIndex = $this->indexer->indexSkills();

        return $this->skillIndex;
    }

    /**
     * Parse a SKILL.md file and extract metadata.
     * Delegates to SkillFileParser.
     */
    protected function parseSkillFile(string $path): ?array
    {
        return $this->parser->parse($path);
    }

    /**
     * Extract keywords from text.
     * Delegates to the shared KeywordExtractor utility.
     */
    protected function extractKeywords(string $text): array
    {
        return KeywordExtractor::extract($text, 20);
    }

    /**
     * Search for skills matching a query.
     */
    public function search(string $query, int $limit = 5): array
    {
        if (empty($this->skillIndex)) {
            $this->indexSkills();
        }

        $queryKeywords = $this->extractKeywords($query);
        $results = [];

        foreach ($this->skillIndex as $skillName => $skill) {
            $score = 0;
            $matchedKeywords = [];

            // Calculate keyword overlap
            foreach ($queryKeywords as $keyword) {
                if (in_array($keyword, $skill['keywords'])) {
                    $score += 2;
                    $matchedKeywords[] = $keyword;
                }
            }

            // Check description for query terms
            $descLower = strtolower($skill['description']);
            foreach ($queryKeywords as $keyword) {
                if (str_contains($descLower, $keyword)) {
                    $score += 1;
                }
            }

            // Direct name match
            if (str_contains(strtolower($skillName), strtolower($query))) {
                $score += 5;
            }

            if ($score > 0) {
                $results[] = [
                    'skill' => $skill,
                    'score' => $score,
                    'matched_keywords' => $matchedKeywords,
                ];
            }
        }

        // Sort by score descending
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Find the best matching skill for a query.
     */
    public function findBestMatch(string $query): ?array
    {
        $results = $this->search($query, 1);

        return $results[0] ?? null;
    }

    /**
     * Get all indexed skills.
     */
    public function getAllSkills(): array
    {
        if (empty($this->skillIndex)) {
            $this->indexSkills();
        }

        return $this->skillIndex;
    }

    /**
     * Get a specific skill by name.
     */
    public function getSkill(string $name): ?array
    {
        if (empty($this->skillIndex)) {
            $this->indexSkills();
        }

        return $this->skillIndex[$name] ?? null;
    }

    /**
     * Get the full SKILL.md content for a skill.
     */
    public function getSkillContent(string $name): ?string
    {
        $skill = $this->getSkill($name);
        if (! $skill) {
            return null;
        }

        return File::get($skill['path']);
    }

    /**
     * Get reference files for a skill.
     */
    public function getSkillReferences(string $name): array
    {
        $skill = $this->getSkill($name);
        if (! $skill || ! $skill['has_references']) {
            return [];
        }

        $refDir = $skill['directory'].'/references';
        $files = File::files($refDir);

        return array_map(fn ($f) => [
            'name' => $f->getFilename(),
            'path' => $f->getPathname(),
        ], $files);
    }

    /**
     * Get scripts for a skill.
     */
    public function getSkillScripts(string $name): array
    {
        $skill = $this->getSkill($name);
        if (! $skill || ! $skill['has_scripts']) {
            return [];
        }

        $scriptsDir = $skill['directory'].'/scripts';
        $files = File::files($scriptsDir);

        return array_map(fn ($f) => [
            'name' => $f->getFilename(),
            'path' => $f->getPathname(),
        ], $files);
    }

    /**
     * Refresh the skill index.
     */
    public function refreshIndex(): array
    {
        $this->skillIndex = [];
        $this->skillIndex = $this->indexer->refreshIndex();

        return $this->skillIndex;
    }

    /**
     * Get skills directories (all configured directories).
     */
    public function getSkillsDirs(): array
    {
        return $this->indexer->getSkillsDirs();
    }

    /**
     * Get the primary skills directory path (for backwards compatibility).
     */
    public function getSkillsDir(): string
    {
        return $this->indexer->getSkillsDir();
    }

    /**
     * Suggest skills based on message intent and content.
     * Uses cache for faster lookups on repeated similar queries.
     */
    public function suggestSkillsForMessage(string $message, array $context = []): array
    {
        // Extract keywords for cache lookup
        $keywords = $this->extractKeywords($message);

        // Check skill match cache first
        $cacheHit = $this->matchCache->findMatch($keywords);

        if ($cacheHit && $cacheHit->skill) {
            return $this->matchCache->buildCacheHitResult($cacheHit, $keywords);
        }

        // Fall back to keyword-based search
        $results = $this->search($message, 10);

        // Add context-based boosting
        if (! empty($context['intent'])) {
            foreach ($results as &$result) {
                if (str_contains(strtolower($result['skill']['description']), $context['intent'])) {
                    $result['score'] += 2;
                }
            }
            unset($result);
        }

        // Re-sort after boosting
        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        $results = array_slice($results, 0, 5);

        // Store the best match in cache for future lookups
        if (! empty($results) && $results[0]['score'] > 0) {
            $this->matchCache->store($keywords, $results[0], $message, $context);
        }

        return $results;
    }

    /**
     * Find skill by message with cache-first lookup.
     * Returns the best matching skill or null.
     */
    public function findSkillForMessage(string $message, array $context = []): ?array
    {
        $results = $this->suggestSkillsForMessage($message, $context);

        return $results[0] ?? null;
    }

    /**
     * Get cache statistics.
     */
    public function getCacheStatistics(): SkillMatchStatisticsDTO
    {
        return $this->matchCache->getStatistics();
    }

    /**
     * Clear the skill match cache.
     */
    public function clearMatchCache(): void
    {
        $this->matchCache->clearAll();
    }

    /**
     * Clean up old cache entries.
     */
    public function cleanupCache(int $daysOld = 30, int $minHits = 2): int
    {
        return $this->matchCache->cleanup($daysOld, $minHits);
    }
}
