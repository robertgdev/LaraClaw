<?php

namespace App\Services;

use App\DTOs\ParsedSkillDTO;
use App\DTOs\SkillDTO;
use App\DTOs\SkillFileDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\DTOs\SkillSearchResultDTO;
use App\Services\Skills\SkillFileParser;
use App\Services\Skills\SkillIndexer;
use App\Services\Skills\SkillMatchCache;
use App\TypedCollections\ParsedSkillDTOCollection;
use App\TypedCollections\SkillDTOCollection;
use App\TypedCollections\SkillFileDTOCollection;
use App\TypedCollections\SkillSearchResultDTOCollection;
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

    protected ParsedSkillDTOCollection $skillIndex;

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
    public function indexSkills(): ParsedSkillDTOCollection
    {
        $this->skillIndex = $this->indexer->indexSkills();

        return $this->skillIndex;
    }

    /**
     * Parse a SKILL.md file and extract metadata.
     * Delegates to SkillFileParser.
     */
    protected function parseSkillFile(string $path): ?ParsedSkillDTO
    {
        return $this->parser->parse($path);
    }

    /**
     * Extract keywords from text.
     * Delegates to the shared KeywordExtractor utility.
     *
     * @return array<string>
     */
    protected function extractKeywords(string $text): array
    {
        return KeywordExtractor::extract($text, 20);
    }

    /**
     * Search for skills matching a query.
     */
    public function search(string $query, int $limit = 5): SkillSearchResultDTOCollection
    {
        if (! isset($this->skillIndex)) {
            $this->indexSkills();
        }

        $queryKeywords = $this->extractKeywords($query);
        $results = [];

        foreach ($this->skillIndex as $skill) {
            $score = 0;
            $matchedKeywords = [];

            // Calculate keyword overlap
            foreach ($queryKeywords as $keyword) {
                if (in_array($keyword, $skill->keywords)) {
                    $score += 2;
                    $matchedKeywords[] = $keyword;
                }
            }

            // Check description for query terms
            $descLower = strtolower($skill->description);
            foreach ($queryKeywords as $keyword) {
                if (str_contains($descLower, $keyword)) {
                    $score += 1;
                }
            }

            // Direct name match
            if (str_contains(strtolower($skill->name), strtolower($query))) {
                $score += 5;
            }

            if ($score > 0) {
                $results[] = new SkillSearchResultDTO(
                    skill: $skill->toSkillDTO(),
                    score: $score,
                    matchedKeywords: $matchedKeywords,
                );
            }
        }

        // Sort by score descending and limit
        usort($results, fn ($a, $b) => $b->score <=> $a->score);
        $results = array_slice($results, 0, $limit);

        return new SkillSearchResultDTOCollection($results);
    }

    /**
     * Find the best matching skill for a query.
     */
    public function findBestMatch(string $query): ?SkillSearchResultDTO
    {
        $results = $this->search($query, 1);

        return $results->first();
    }

    /**
     * Get all indexed skills.
     */
    public function getAllSkills(): SkillDTOCollection
    {
        if (! isset($this->skillIndex)) {
            $this->indexSkills();
        }

        $skills = $this->skillIndex->map(
            fn (ParsedSkillDTO $skill) => $skill->toSkillDTO()
        );

        return new SkillDTOCollection($skills->values()->all());
    }

    /**
     * Get a specific skill by name.
     */
    public function getSkill(string $name): ?SkillDTO
    {
        if (! isset($this->skillIndex)) {
            $this->indexSkills();
        }

        $parsed = $this->skillIndex->findByName($name);

        return $parsed?->toSkillDTO();
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

        return File::get($skill->path);
    }

    /**
     * Get reference files for a skill.
     */
    public function getSkillReferences(string $name): SkillFileDTOCollection
    {
        $skill = $this->getSkill($name);
        if (! $skill || ! $skill->hasReferences) {
            return new SkillFileDTOCollection([]);
        }

        $refDir = $skill->directory.'/references';
        $files = File::files($refDir);

        $dtos = array_map(fn ($f) => new SkillFileDTO(
            name: $f->getFilename(),
            path: $f->getPathname(),
        ), $files);

        return new SkillFileDTOCollection($dtos);
    }

    /**
     * Get scripts for a skill.
     */
    public function getSkillScripts(string $name): SkillFileDTOCollection
    {
        $skill = $this->getSkill($name);
        if (! $skill || ! $skill->hasScripts) {
            return new SkillFileDTOCollection([]);
        }

        $scriptsDir = $skill->directory.'/scripts';
        $files = File::files($scriptsDir);

        $dtos = array_map(fn ($f) => new SkillFileDTO(
            name: $f->getFilename(),
            path: $f->getPathname(),
        ), $files);

        return new SkillFileDTOCollection($dtos);
    }

    /**
     * Refresh the skill index.
     */
    public function refreshIndex(): ParsedSkillDTOCollection
    {
        $this->skillIndex = $this->indexer->refreshIndex();

        return $this->skillIndex;
    }

    /**
     * Get skills directories (all configured directories).
     *
     * @return array<array{type: string, path: string}>
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
     *
     * @param  string  $message  The message to analyze
     * @param  array<string, mixed>  $context  Additional context for matching
     */
    public function suggestSkillsForMessage(string $message, array $context = []): SkillSearchResultDTOCollection
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
            $boostedResults = [];
            foreach ($results as $result) {
                if (str_contains(strtolower($result->skill->description), $context['intent'])) {
                    $boostedResults[] = new SkillSearchResultDTO(
                        skill: $result->skill,
                        score: $result->score + 2,
                        matchedKeywords: $result->matchedKeywords,
                        fromCache: $result->fromCache,
                        cacheHitId: $result->cacheHitId,
                    );
                } else {
                    $boostedResults[] = $result;
                }
            }
            $results = new SkillSearchResultDTOCollection($boostedResults);
        }

        // Re-sort after boosting and limit
        $sorted = $results->sortByDesc('score')->take(5)->values();

        // Store the best match in cache for future lookups
        $bestMatch = $sorted->first();
        if ($bestMatch && $bestMatch->score > 0) {
            $this->matchCache->store($keywords, $bestMatch, $message, $context);
        }

        return new SkillSearchResultDTOCollection($sorted->all());
    }

    /**
     * Find skill by message with cache-first lookup.
     * Returns the best matching skill or null.
     *
     * @param  string  $message  The message to analyze
     * @param  array<string, mixed>  $context  Additional context for matching
     */
    public function findSkillForMessage(string $message, array $context = []): ?SkillSearchResultDTO
    {
        $results = $this->suggestSkillsForMessage($message, $context);

        return $results->first();
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
