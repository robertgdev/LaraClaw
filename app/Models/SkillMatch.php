<?php

namespace App\Models;

use App\DTOs\IntentClassificationDTO;
use App\DTOs\IntentMappingDTO;
use App\DTOs\SkillMatchStatisticsDTO;
use App\Services\Skills\SignatureGenerator;
use App\Services\Skills\SkillMatchRepository;
use App\Services\Skills\SkillMatchStatisticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Skill Match Cache Model
 *
 * Caches intent→skill mappings to reduce LLM calls for skill matching.
 * Stores normalized intent signatures and matched skills for fast lookup.
 *
 * Business logic (matching strategies, caching, statistics) is delegated to:
 * - {@see SkillMatchRepository} for CRUD and matching operations
 * - {@see SignatureGenerator} for keyword normalization and hashing
 * - {@see SkillMatchStatisticsService} for aggregated statistics
 *
 * Static methods are retained as backward-compatible proxies that delegate
 * to singleton instances of the extracted services.
 *
 * @property int $id
 * @property string $intent_signature MD5 hash of sorted keywords
 * @property array<int, string> $intent_keywords Extracted keywords from message
 * @property int $skill_id Foreign key to laraclaw_skills table
 * @property float $confidence_score Confidence score (0.00 to 1.00)
 * @property string|null $sample_message Original message sample
 * @property int $hit_count Usage tracking
 * @property string|null $intent_category Intent category
 * @property array<string, mixed>|null $entities Extracted entities
 * @property string|null $suggested_agent Agent suggestion
 * @property array<string, mixed>|null $metadata Additional metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Skill $skill The matched skill
 */
class SkillMatch extends Model
{
    protected $fillable = [
        'intent_signature',
        'intent_keywords',
        'skill_id',
        'confidence_score',
        'sample_message',
        'hit_count',
        'intent_category',
        'entities',
        'suggested_agent',
        'metadata',
    ];

    protected $casts = [
        'intent_keywords' => 'array',
        'entities' => 'array',
        'metadata' => 'array',
        'confidence_score' => 'float',
        'hit_count' => 'integer',
    ];

    // ==========================================
    // Cache Key Constants
    // ==========================================

    const CACHE_KEY_PREFIX = 'skill_match:';

    const CACHE_TTL = 3600; // 1 hour

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the skill this match belongs to.
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    /**
     * Get the suggested agent for this cache entry.
     */
    public function suggestedAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'suggested_agent', 'agent_id');
    }

    // ==========================================
    // Query Scopes
    // ==========================================

    public function scopeBySignature(Builder $query, string $signature): Builder
    {
        return $query->where('intent_signature', $signature);
    }

    public function scopeForSkill(Builder $query, int $skillId): Builder
    {
        return $query->where('skill_id', $skillId);
    }

    public function scopeForSkillName(Builder $query, string $skillName): Builder
    {
        return $query->whereHas('skill', fn ($q) => $q->where('name', $skillName));
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('intent_category', $category);
    }

    public function scopeMinConfidence(Builder $query, float $minConfidence): Builder
    {
        return $query->where('confidence_score', '>=', $minConfidence);
    }

    public function scopeHighConfidence(Builder $query): Builder
    {
        return $query->where('confidence_score', '>=', 0.8);
    }

    public function scopeMediumConfidence(Builder $query): Builder
    {
        return $query->where('confidence_score', '>=', 0.5);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->orderByDesc('hit_count');
    }

    public function scopeByConfidence(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('confidence_score', $direction);
    }

    public function scopeForAgent(Builder $query, string $agentId): Builder
    {
        return $query->where('suggested_agent', $agentId);
    }

    public function scopeWithKeyword(Builder $query, string $keyword): Builder
    {
        return $query->whereJsonContains('intent_keywords', $keyword);
    }

    public function scopeWithAnyKeyword(Builder $query, array $keywords): Builder
    {
        return $query->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhereJsonContains('intent_keywords', $keyword);
            }
        });
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFrequent(Builder $query, int $minHits = 5): Builder
    {
        return $query->where('hit_count', '>=', $minHits);
    }

    // ==========================================
    // Instance Methods
    // ==========================================

    /**
     * Increment hit count for this entry.
     */
    public function recordHit(): void
    {
        $this->increment('hit_count');
    }

    /**
     * Convert to IntentClassificationDTO.
     */
    public function toDTO(): IntentClassificationDTO
    {
        return new IntentClassificationDTO(
            intent: $this->intent_category ?? 'unknown',
            confidence: $this->confidence_score,
            matchedSkill: $this->skill->name ?? 'unknown',
            entities: $this->entities ?? [],
            suggestedAgent: $this->suggested_agent,
            method: 'cache_hit',
            fromCache: true,
            keywords: $this->intent_keywords ?? [],
        );
    }

    // ==========================================
    // Static Proxy Methods (backward compatibility)
    // ==========================================

    /**
     * Get a shared repository instance.
     */
    protected static function repository(): SkillMatchRepository
    {
        return app(SkillMatchRepository::class);
    }

    /**
     * Get a shared statistics service instance.
     */
    protected static function statisticsService(): SkillMatchStatisticsService
    {
        return app(SkillMatchStatisticsService::class);
    }

    /**
     * Generate a normalized signature from keywords.
     *
     * @param  array<int, string>  $keywords
     */
    public static function generateSignature(array $keywords): string
    {
        return (new SignatureGenerator)->generate($keywords);
    }

    /**
     * Find a cache entry by signature.
     */
    public static function findBySignature(string $signature): ?self
    {
        return static::repository()->findBySignature($signature);
    }

    /**
     * Find a cache entry by keywords.
     *
     * @param  array<int, string>  $keywords
     */
    public static function findByKeywords(array $keywords): ?self
    {
        return static::repository()->findByKeywords($keywords);
    }

    /**
     * Find similar entries by keyword overlap.
     *
     * @param  array<int, string>  $keywords
     */
    public static function findSimilar(array $keywords, float $minConfidence = 0.7): ?self
    {
        return static::repository()->findSimilar($keywords, $minConfidence);
    }

    /**
     * Store a new cache entry or update existing.
     *
     * @param  array<string, mixed>|null  $entities
     * @param  array<string, mixed>|null  $metadata
     */
    public static function storeMatch(
        IntentMappingDTO $intentMapping,
        ?array $entities = null,
        ?string $agent = null,
        ?array $metadata = null
    ): self {
        return static::repository()->storeMatch(
            keywords: $intentMapping->keywords,
            skillId: $intentMapping->skillId,
            confidence: $intentMapping->confidence,
            category: $intentMapping->category,
            entities: $entities,
            agent: $agent,
            sampleMessage: $intentMapping->sampleIntent,
            metadata: $metadata
        );
    }

    /**
     * Store a match by skill name (convenience method).
     *
     * @param  array<int, string>  $keywords
     * @param  array<string, mixed>|null  $entities
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws \InvalidArgumentException If skill not found
     */
    // FIXME: convert to DTO
    public static function storeMatchBySkillName(
        array $keywords,
        string $skillName,
        float $confidence,
        ?string $category = null,
        ?array $entities = null,
        ?string $agent = null,
        ?string $sampleMessage = null,
        ?array $metadata = null
    ): self {
        return static::repository()->storeMatchBySkillName(
            keywords: $keywords,
            skillName: $skillName,
            confidence: $confidence,
            category: $category,
            entities: $entities,
            agent: $agent,
            sampleMessage: $sampleMessage,
            metadata: $metadata
        );
    }

    /**
     * Get statistics about the cache.
     */
    public static function getStatistics(): SkillMatchStatisticsDTO
    {
        return static::statisticsService()->getStatistics();
    }

    /**
     * Clear all cache entries.
     */
    public static function clearAll(): void
    {
        static::repository()->clearAll();
    }

    /**
     * Clean up old entries with low hit counts.
     */
    public static function cleanup(int $daysOld = 30, int $minHits = 2): int
    {
        return static::repository()->cleanup($daysOld, $minHits);
    }
}
