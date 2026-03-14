<?php

declare(strict_types=1);

namespace App\Services\Memory;

use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Scores memory relevance using hybrid ranking.
 *
 * Combines three signals:
 * - Full-text search score (normalized 0-1)
 * - Temporal decay via Ebbinghaus forgetting curve
 * - Importance weight from the memory record
 *
 * Scoring formula:
 *   relevance = (fts_score × fts_weight) + (temporal_score × temporal_weight) + (importance × importance_weight)
 */
class MemoryRelevanceScorer
{
    private const MS_PER_DAY = 86400000;

    /**
     * Compute combined relevance score for a memory record.
     *
     * @param  float  $rawFtsScore  Raw full-text search score
     * @param  float  $maxFtsScore  Maximum FTS score in the result set (for normalization)
     * @param  int  $lastAccessedAtMs  Last access timestamp in milliseconds
     * @param  int  $accessCount  Number of times the memory has been accessed
     * @param  float  $importance  Importance score (0.0 - 1.0)
     * @param  int  $nowMs  Current timestamp in milliseconds
     */
    public function score(
        float $rawFtsScore,
        float $maxFtsScore,
        int $lastAccessedAtMs,
        int $accessCount,
        float $importance,
        int $nowMs
    ): float {
        $ftsScore = $this->normalizeScore($rawFtsScore, $maxFtsScore);
        $temporalScore = $this->computeTemporalScore($lastAccessedAtMs, $accessCount, $nowMs);

        return ($ftsScore * $this->getWeight('fts'))
            + ($temporalScore * $this->getWeight('temporal'))
            + ($importance * $this->getWeight('importance'));
    }

    /**
     * Compute temporal score using Ebbinghaus forgetting curve.
     *
     * Formula: e^(-rate × days_since_access) × (1 + bonus × access_count)
     */
    public function computeTemporalScore(
        int $lastAccessedAt,
        int $accessCount,
        int $now
    ): float {
        $rate = config('memory.decay.rate', 0.05);
        $bonus = config('memory.decay.access_bonus', 0.02);

        $daysSinceAccess = max(0, ($now - $lastAccessedAt) / self::MS_PER_DAY);
        $decay = exp(-$rate * $daysSinceAccess);
        $accessBonus = 1 + ($bonus * $accessCount);

        return min(1.0, $decay * $accessBonus);
    }

    /**
     * Normalize a score to 0-1 range.
     */
    public function normalizeScore(float $score, float $max): float
    {
        return $max > 0 ? min(1.0, $score / $max) : 0.0;
    }

    /**
     * Calculate Jaccard similarity between two strings.
     */
    public function contentSimilarity(string $a, string $b): float
    {
        $tokensA = collect($this->tokenize($a))->filter(fn ($w) => strlen($w) > 2);
        $tokensB = collect($this->tokenize($b))->filter(fn ($w) => strlen($w) > 2);

        if ($tokensA->isEmpty() || $tokensB->isEmpty()) {
            return 0.0;
        }

        $overlap = $tokensA->intersect($tokensB)->count();
        $union = $tokensA->merge($tokensB)->unique()->count();

        return $union > 0 ? $overlap / $union : 0.0;
    }

    /**
     * Tokenize a query string.
     *
     * @return array<int, string>
     */
    public function tokenize(string $text): array
    {
        return array_filter(
            preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', ' ', strtolower($text))),
            fn ($w) => strlen($w) > 1
        );
    }

    /**
     * Get a scoring weight from config.
     */
    public function getWeight(string $type): float
    {
        return config("memory.scoring.{$type}_weight", match ($type) {
            'fts' => 0.4,
            'temporal' => 0.3,
            'importance' => 0.3,
            default => 0.33,
        });
    }
}
