<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Models\Skill;
use App\Models\SkillMatch;
use Illuminate\Support\Facades\Cache;

/**
 * Repository for SkillMatch CRUD and lookup operations.
 *
 * Encapsulates the two-tier matching strategy (exact signature + fuzzy keyword overlap),
 * in-process caching, and storage logic that was previously in the model's static methods.
 */
class SkillMatchRepository
{
    protected SignatureGenerator $signatureGenerator;

    public function __construct(?SignatureGenerator $signatureGenerator = null)
    {
        $this->signatureGenerator = $signatureGenerator ?? new SignatureGenerator;
    }

    /**
     * Find a cache entry by signature.
     * Uses Laravel Cache for in-process memory caching.
     */
    public function findBySignature(string $signature): ?SkillMatch
    {
        $cacheKey = SkillMatch::CACHE_KEY_PREFIX.$signature;

        return Cache::memo()->remember($cacheKey, SkillMatch::CACHE_TTL, function () use ($signature) {
            return SkillMatch::bySignature($signature)->first();
        });
    }

    /**
     * Find a cache entry by keywords.
     *
     * @param  array<int, string>  $keywords
     */
    public function findByKeywords(array $keywords): ?SkillMatch
    {
        $signature = $this->signatureGenerator->generate($keywords);

        return $this->findBySignature($signature);
    }

    /**
     * Find similar entries by keyword overlap.
     *
     * @param  array<int, string>  $keywords
     */
    public function findSimilar(array $keywords, float $minConfidence = 0.7): ?SkillMatch
    {
        // Try exact match first
        $exact = $this->findByKeywords($keywords);
        if ($exact && $exact->confidence_score >= $minConfidence) {
            return $exact;
        }

        // Find by keyword overlap
        return SkillMatch::withAnyKeyword($keywords)
            ->minConfidence($minConfidence)
            ->popular()
            ->first();
    }

    /**
     * Store a new cache entry or update existing.
     *
     * @param  array<int, string>  $keywords
     * @param  array<string, mixed>  $entities
     * @param  array<string, mixed>  $metadata
     */
    public function storeMatch(
        array $keywords,
        int $skillId,
        float $confidence,
        ?string $category = null,
        ?array $entities = null,
        ?string $agent = null,
        ?string $sampleMessage = null,
        ?array $metadata = null
    ): SkillMatch {
        $signature = $this->signatureGenerator->generate($keywords);

        $cache = SkillMatch::updateOrCreate(
            ['intent_signature' => $signature],
            [
                'intent_keywords' => $keywords,
                'skill_id' => $skillId,
                'confidence_score' => $confidence,
                'intent_category' => $category,
                'entities' => $entities,
                'suggested_agent' => $agent,
                'sample_message' => $sampleMessage ? substr($sampleMessage, 0, 500) : null,
                'metadata' => $metadata,
            ]
        );

        // Clear the cache for this signature
        Cache::memo()->forget(SkillMatch::CACHE_KEY_PREFIX.$signature);

        return $cache;
    }

    /**
     * Store a match by skill name (convenience method).
     *
     * @param  array<int, string>  $keywords
     * @param  array<string, mixed>  $entities
     * @param  array<string, mixed>  $metadata
     *
     * @throws \InvalidArgumentException If skill not found
     */
    public function storeMatchBySkillName(
        array $keywords,
        string $skillName,
        float $confidence,
        ?string $category = null,
        ?array $entities = null,
        ?string $agent = null,
        ?string $sampleMessage = null,
        ?array $metadata = null
    ): SkillMatch {
        $skill = Skill::findByName($skillName);

        if (! $skill) {
            throw new \InvalidArgumentException("Skill not found: {$skillName}");
        }

        return $this->storeMatch(
            keywords: $keywords,
            skillId: $skill->id,
            confidence: $confidence,
            category: $category,
            entities: $entities,
            agent: $agent,
            sampleMessage: $sampleMessage,
            metadata: $metadata
        );
    }

    /**
     * Clear all cache entries.
     */
    public function clearAll(): void
    {
        // Clear Laravel cache for all entries
        $signatures = SkillMatch::pluck('intent_signature');
        foreach ($signatures as $signature) {
            Cache::memo()->forget(SkillMatch::CACHE_KEY_PREFIX.$signature);
        }

        // Truncate the table
        SkillMatch::truncate();
    }

    /**
     * Clean up old entries with low hit counts.
     */
    public function cleanup(int $daysOld = 30, int $minHits = 2): int
    {
        return SkillMatch::where('created_at', '<', now()->subDays($daysOld))
            ->where('hit_count', '<', $minHits)
            ->delete();
    }

    /**
     * Generate a signature from keywords.
     *
     * @param  array<int, string>  $keywords
     */
    public function generateSignature(array $keywords): string
    {
        return $this->signatureGenerator->generate($keywords);
    }

    /**
     * Get the signature generator.
     */
    public function getSignatureGenerator(): SignatureGenerator
    {
        return $this->signatureGenerator;
    }
}
