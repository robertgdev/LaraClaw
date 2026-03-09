<?php

declare(strict_types=1);

namespace App\Services\Intent;

use App\DTOs\IntentClassificationDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\Logging\MultiLogger;
use App\Models\SkillMatch;
use Illuminate\Support\Facades\Cache;

/**
 * IntentCacheManager - Manages the two-tier intent classification cache.
 *
 * Tier 1: Laravel in-memory cache (keyed by message hash)
 * Tier 2: Database-backed SkillMatch cache (keyed by keyword signature)
 *
 * This class encapsulates all cache read/write/clear/statistics operations
 * for the intent classification subsystem.
 */
class IntentCacheManager
{
    protected int $cacheTTL;

    protected float $cacheMinConfidence;

    public function __construct()
    {
        $this->cacheTTL = config('laraclaw.intent_classification.cache_ttl', 3600);
        $this->cacheMinConfidence = config('laraclaw.intent_classification.cache_min_confidence', 0.7);
    }

    /**
     * Try to find a cached classification result.
     *
     * Checks in-memory cache first, then database cache.
     *
     * @param  string  $message  The user message
     * @param  array<int,string>  $keywords  Extracted keywords
     */
    public function find(string $message, array $keywords): ?IntentClassificationDTO
    {
        // Tier 1: Laravel cache (by message hash)
        $cacheKey = 'intent:'.md5($message);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        // Tier 2: Database cache (by keyword signature)
        $row = SkillMatch::findSimilar($keywords, $this->cacheMinConfidence);
        if ($row) {
            $row->recordHit();

            return $row->toDTO();
        }

        return null;
    }

    /**
     * Store a classification result in both cache tiers.
     *
     * @param  IntentClassificationDTO  $result  The classification result
     * @param  array<int,string>  $keywords  Extracted keywords
     * @param  string  $message  The original message
     */
    public function store(IntentClassificationDTO $result, array $keywords, string $message): void
    {
        // Tier 1: Laravel cache
        $cacheKey = 'intent:'.md5($message);
        Cache::put($cacheKey, $result, $this->cacheTTL);

        // Tier 2: Database cache
        if (! $keywords) {
            return;
        }

        try {
            SkillMatch::storeMatchBySkillName(
                keywords: $keywords,
                skillName: $result->matchedSkill ?? 'none',
                confidence: $result->skillConfidence ?? $result->confidence,
                category: $result->intent,
                entities: $result->entities ?? [],
                agent: $result->suggestedAgent,
                sampleMessage: $message,
                metadata: [
                    'reasoning' => $result->reasoning ?? null,
                    'method' => $result->method ?? 'llm',
                ]
            );
        } catch (\Exception $e) {
            MultiLogger::warning('Failed to store skill match cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Store a result in the in-memory cache only.
     */
    public function storeInMemory(IntentClassificationDTO $result, string $message): void
    {
        $cacheKey = 'intent:'.md5($message);
        Cache::put($cacheKey, $result, $this->cacheTTL);
    }

    /**
     * Get cache statistics.
     */
    public function getStatistics(): SkillMatchStatisticsDTO
    {
        return SkillMatch::getStatistics();
    }

    /**
     * Clear all cache entries.
     */
    public function clearAll(): void
    {
        SkillMatch::clearAll();
    }
}
