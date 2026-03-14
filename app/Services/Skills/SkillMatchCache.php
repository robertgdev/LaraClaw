<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\DTOs\SkillDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\DTOs\SkillSearchResultDTO;
use App\Logging\MultiLogger;
use App\Models\SkillMatch;
use App\TypedCollections\SkillSearchResultDTOCollection;

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
     *
     * @param  array<int, string>  $keywords
     */
    public function findMatch(array $keywords): ?SkillMatch
    {
        return SkillMatch::findSimilar($keywords, $this->minConfidence);
    }

    /**
     * Store a skill search result in the database cache.
     *
     * @param  array<int, string>  $keywords  Extracted keywords from the user message
     * @param  array<string, mixed>  $context  Context data (intent, entities, etc.)
     */
    public function store(array $keywords, SkillSearchResultDTO $result, string $message, array $context = []): void
    {
        if (empty($keywords)) {
            return;
        }

        try {
            $confidence = $result->score / 20; // Normalize score to 0-1 range
            $confidence = min(1.0, max(0.0, $confidence));

            SkillMatch::storeMatchBySkillName(
                keywords: $keywords,
                skillName: $result->skill->name,
                confidence: $confidence,
                category: $context['intent'] ?? null,
                entities: $context['entities'] ?? null,
                agent: $context['suggested_agent'] ?? null,
                sampleMessage: $message,
                metadata: [
                    'matched_keywords' => $result->matchedKeywords,
                    'source_type' => $result->skill->sourceType,
                ]
            );

            MultiLogger::debug('Stored skill match in cache', [
                'skill' => $result->skill->name,
                'confidence' => $confidence,
            ]);
        } catch (\Exception $e) {
            MultiLogger::warning('Failed to store skill match cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a search result collection from a cached SkillMatch.
     *
     * @param  array<string>  $keywords
     */
    public function buildCacheHitResult(SkillMatch $cacheHit, array $keywords): SkillSearchResultDTOCollection
    {
        $cacheHit->recordHit();

        MultiLogger::debug('Skill match cache hit', [
            'skill' => $cacheHit->skill->name,
            'confidence' => $cacheHit->confidence_score,
        ]);

        $skill = new SkillDTO(
            name: $cacheHit->skill->name,
            dirName: $cacheHit->skill->dir_name,
            description: $cacheHit->skill->description,
            path: $cacheHit->skill->path,
            directory: dirname($cacheHit->skill->path),
            keywords: $cacheHit->skill->keywords ?? [],
            hasScripts: $cacheHit->skill->has_scripts,
            hasReferences: $cacheHit->skill->has_references,
            hasAssets: $cacheHit->skill->has_assets,
            license: $cacheHit->skill->license,
            sourceType: $cacheHit->skill->source_type,
        );

        $result = new SkillSearchResultDTO(
            skill: $skill,
            score: (int) ($cacheHit->confidence_score * 10),
            matchedKeywords: $keywords,
            fromCache: true,
            cacheHitId: $cacheHit->id,
        );

        return new SkillSearchResultDTOCollection([$result]);
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
