<?php

namespace App\Services\Memory;

use App\Enums\ChannelEnum;
use App\Models\Memory;
use Illuminate\Support\Collection;

use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Scout-based search strategy.
 *
 * Works with all Laravel Scout drivers:
 * - Meilisearch (recommended for production)
 * - Typesense
 * - Algolia
 * - Database (LIKE-based fallback)
 */
class ScoutSearchStrategy implements SearchStrategyInterface
{
    /**
     * Search episodic memories using Laravel Scout.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    public function search(
        string $senderId,
        ChannelEnum $channel,
        string $query,
        int $limit
    ): Collection {
        $driver = config('scout.driver');

        // Build the search query using Scout's unified API
        $builder = Memory::search($query);

        // Apply filters via Scout's fluent API (works across all drivers)
        $results = $builder
            ->where('sender_id', $senderId)
            ->where('channel', $channel->value)
            ->take($limit)
            ->get();

        // Normalize scores from different Scout drivers
        return $this->normalizeScores($results, $query, $driver);
    }

    public function getName(): string
    {
        return 'scout_'.config('scout.driver');
    }

    /**
     * Normalize scores from different Scout drivers.
     *
     * Different drivers return scores in different formats:
     * - Meilisearch: _rankingScore (0-1)
     * - Algolia: _score
     * - Typesense: _rankingScore
     * - Database: no score (we calculate our own)
     *
     * @param  Collection<int, \App\Models\Memory>  $results
     * @return Collection<int, \App\Models\Memory>
     */
    private function normalizeScores(Collection $results, string $query, string $driver): Collection
    {
        if ($results->isEmpty()) {
            return $results;
        }

        return $results->map(function ($item, $index) use ($driver, $query) {
            // Different drivers return scores differently
            $item->search_score = match ($driver) {
                // Meilisearch returns _rankingScore (0-1)
                'meilisearch' => $item->scoutMetadata['_rankingScore'] ?? $this->positionBasedScore($index),

                // Algolia returns _score
                'algolia' => $item->scoutMetadata['_score'] ?? $this->positionBasedScore($index),

                // Typesense returns _rankingScore
                'typesense' => $item->scoutMetadata['_rankingScore'] ?? $this->positionBasedScore($index),

                // Database driver has no score - calculate based on content matching
                'database', 'collection' => $this->calculateLikeScore($item, $query),

                default => $this->positionBasedScore($index),
            };

            return $item;
        });
    }

    /**
     * Calculate relevance score for database driver (LIKE search).
     *
     * This provides a simple relevance score based on:
     * - Exact term matches
     * - Partial/prefix matches
     */
    private function calculateLikeScore(Memory $item, string $query): float
    {
        $text = strtolower($item->content.' '.$item->outcome);
        $terms = $this->tokenize($query);

        if (empty($terms)) {
            return 0.0;
        }

        $score = 0.0;

        foreach ($terms as $term) {
            // Exact match = higher score
            if (str_contains($text, $term)) {
                $score += 1.0;
            }

            // Partial/prefix match
            $words = preg_split('/\s+/', $text);
            foreach ($words as $word) {
                if (str_starts_with($word, $term)) {
                    $score += 0.5;
                }
            }
        }

        return $score / count($terms);
    }

    /**
     * Position-based score fallback.
     *
     * When a driver doesn't provide scores, we use position
     * as a proxy (earlier results = higher score).
     */
    private function positionBasedScore(int $index): float
    {
        return max(0.1, 1.0 - ($index * 0.05));
    }

    /**
     * Tokenize a query string.
     *
     * @return array<int, string>
     */
    private function tokenize(string $query): array
    {
        return array_filter(
            preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9\s]/', ' ', $query))),
            fn ($w) => strlen($w) > 1
        );
    }
}
