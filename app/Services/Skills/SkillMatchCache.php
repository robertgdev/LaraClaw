<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\SkillMatchStatisticsDTO;
use App\Logging\MultiLogger;
use App\Models\SkillMatch;

/**
 * Database-backed cache layer for skill matching results.
 *
 * Manages storage, retrieval, and cleanup of skill match entries
 * in the SkillMatch model. Separated from SkillSearchService to
 * allow independent evolution of the cache strategy.
 */
class SkillMatchCache
{
    /**
     * Minimum confidence threshold for cache hits.
     */
    protected float $minConfidence = 0.7;

    /**
     * Look up a cached skill match for the given keywords.
     */
    public function findMatch(array $keywords): ?SkillMatch
    {
        return SkillMatch::findSimilar($keywords, $this->minConfidence);
    }

    /**
     * Store a skill search result in the database cache.
     *
     * @param  array  $keywords  Extracted keywords from the user message
     * @param  array  $result  The best search result to cache
     * @param  string  $message  The original user message
     * @param  array  $context  Context data (intent, entities, etc.)
     */
    public function store(array $keywords, array $result, string $message, array $context = []): void
    {
        if (empty($keywords) || empty($result['skill'])) {
            return;
        }

        try {
            $confidence = $result['score'] / 20; // Normalize score to 0-1 range
            $confidence = min(1.0, max(0.0, $confidence));

            SkillMatch::storeMatchBySkillName(
                keywords: $keywords,
                skillName: $result['skill']['name'],
                confidence: $confidence,
                category: $context['intent'] ?? null,
                entities: $context['entities'] ?? null,
                agent: $context['suggested_agent'] ?? null,
                sampleMessage: $message,
                metadata: [
                    'matched_keywords' => $result['matched_keywords'] ?? [],
                    'source_type' => $result['skill']['source_type'] ?? 'unknown',
                ]
            );

            MultiLogger::debug('Stored skill match in cache', [
                'skill' => $result['skill']['name'],
                'confidence' => $confidence,
            ]);
        } catch (\Exception $e) {
            MultiLogger::warning('Failed to store skill match cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a search result array from a cached SkillMatch.
     */
    public function buildCacheHitResult(SkillMatch $cacheHit, array $keywords): array
    {
        $cacheHit->recordHit();

        MultiLogger::debug('Skill match cache hit', [
            'skill' => $cacheHit->skill->name,
            'confidence' => $cacheHit->confidence_score,
        ]);

        return [
            [
                'skill' => [
                    'name' => $cacheHit->skill->name,
                    'description' => $cacheHit->skill->description,
                    'dir_name' => $cacheHit->skill->dir_name,
                    'path' => $cacheHit->skill->path,
                    'keywords' => $cacheHit->skill->keywords ?? [],
                    'source_type' => $cacheHit->skill->source_type,
                    'has_scripts' => $cacheHit->skill->has_scripts,
                    'has_references' => $cacheHit->skill->has_references,
                    'has_assets' => $cacheHit->skill->has_assets,
                ],
                'score' => $cacheHit->confidence_score * 10,
                'matched_keywords' => $keywords,
                'from_cache' => true,
                'cache_hit_id' => $cacheHit->id,
            ],
        ];
    }

    /**
     * Get cache statistics.
     */
    public function getStatistics(): SkillMatchStatisticsDTO
    {
        return SkillMatch::getStatistics();
    }

    /**
     * Clear all cached entries.
     */
    public function clearAll(): void
    {
        SkillMatch::clearAll();
    }

    /**
     * Clean up old entries with low hit counts.
     */
    public function cleanup(int $daysOld = 30, int $minHits = 2): int
    {
        return SkillMatch::cleanup($daysOld, $minHits);
    }

    /**
     * Set the minimum confidence threshold.
     */
    public function setMinConfidence(float $minConfidence): void
    {
        $this->minConfidence = $minConfidence;
    }
}
