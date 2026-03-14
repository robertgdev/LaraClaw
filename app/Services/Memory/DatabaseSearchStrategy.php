<?php

namespace App\Services\Memory;

use App\Enums\ChannelEnum;
use App\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Direct database search strategy without Scout.
 *
 * Uses native database full-text search features:
 * - MySQL: FULLTEXT index with MATCH...AGAINST
 * - SQLite: FTS5 virtual tables (if configured)
 * - PostgreSQL: to_tsvector with to_tsquery
 * - Fallback: LIKE queries
 */
class DatabaseSearchStrategy implements SearchStrategyInterface
{
    /**
     * Search episodic memories using native database features.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    public function search(
        string $senderId,
        ChannelEnum $channel,
        string $query,
        int $limit
    ): Collection {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        $results = match ($driver) {
            // MySQL has native FULLTEXT support
            'mysql' => $this->searchMySQL($senderId, $channel, $query, $limit),

            // PostgreSQL has full-text search
            'pgsql' => $this->searchPostgres($senderId, $channel, $query, $limit),

            // SQLite can use FTS5 via raw queries (if virtual table exists)
            'sqlite' => $this->searchSQLite($senderId, $channel, $query, $limit),

            // Fallback to LIKE
            default => $this->searchLike($senderId, $channel, $query, $limit),
        };

        return $this->normalizeScores($results, $query);
    }

    public function getName(): string
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        return 'database_'.$driver;
    }

    /**
     * Search using MySQL FULLTEXT index.
     *
     * Requires FULLTEXT index on (content, outcome) columns.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    private function searchMySQL(string $senderId, ChannelEnum $channel, string $query, int $limit): Collection
    {
        try {
            return Memory::query()
                ->where('sender_id', $senderId)
                ->where('channel', $channel)
                ->whereRaw('MATCH(content, outcome) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])
                ->orderByRaw('MATCH(content, outcome) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])
                ->limit($limit)
                ->get()
                ->map(function ($item) use ($query) {
                    // MySQL doesn't return a score directly, so we calculate one
                    $item->search_score = $this->calculateMatchScore($item, $query);

                    return $item;
                });
        } catch (\Exception $e) {
            // FULLTEXT might not be available, fallback to LIKE
            return $this->searchLike($senderId, $channel, $query, $limit);
        }
    }

    /**
     * Search using PostgreSQL full-text search.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    private function searchPostgres(string $senderId, ChannelEnum $channel, string $query, int $limit): Collection
    {
        try {
            return Memory::query()
                ->where('sender_id', $senderId)
                ->where('channel', $channel)
                ->whereRaw("to_tsvector('english', content || ' ' || coalesce(outcome, '')) @@ to_tsquery('english', ?)", [$query])
                ->orderByRaw("ts_rank(to_tsvector('english', content || ' ' || coalesce(outcome, '')), to_tsquery('english', ?)) DESC", [$query])
                ->limit($limit)
                ->get()
                ->map(function ($item, $index) {
                    $item->search_score = 1.0 - ($index * 0.05);

                    return $item;
                });
        } catch (\Exception $e) {
            // Fallback to LIKE
            return $this->searchLike($senderId, $channel, $query, $limit);
        }
    }

    /**
     * Search using SQLite FTS5 (if virtual table exists).
     *
     * Note: This requires a separate FTS5 virtual table to be created.
     * Falls back to LIKE if not available.
     *
     * @return Collection<int, \App\Models\Memory>
     */
    private function searchSQLite(string $senderId, ChannelEnum $channel, string $query, int $limit): Collection
    {
        // Check if FTS5 virtual table exists
        try {
            $ftsExists = DB::select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='laraclaw_episodic_memory_fts'"
            );

            if (! empty($ftsExists)) {
                $results = DB::select(
                    'SELECT e.*, f.rank
                     FROM laraclaw_episodic_memory_fts f
                     JOIN laraclaw_episodic_memory e ON e.id = f.id
                     WHERE laraclaw_episodic_memory_fts MATCH ?
                       AND e.sender_id = ?
                       AND e.channel = ?
                     ORDER BY f.rank
                     LIMIT ?',
                    [$query, $senderId, $channel->value, $limit]
                );

                $ids = collect($results)->pluck('id')->toArray();

                return Memory::whereIn('id', $ids)
                    ->orderByRaw('FIELD(id, '.implode(',', $ids).')')
                    ->get()
                    ->map(function ($item, $index) {
                        $item->search_score = 1.0 - ($index * 0.05);

                        return $item;
                    });
            }
        } catch (\Exception $e) {
            // FTS5 not available, fall through to LIKE
        }

        return $this->searchLike($senderId, $channel, $query, $limit);
    }

    /**
     * Search using LIKE queries (fallback for all databases).
     *
     * @return Collection<int, \App\Models\Memory>
     */
    private function searchLike(string $senderId, ChannelEnum $channel, string $query, int $limit): Collection
    {
        $terms = $this->tokenize($query);

        return Memory::query()
            ->where('sender_id', $senderId)
            ->where('channel', $channel)
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhere('content', 'LIKE', "%{$term}%")
                        ->orWhere('outcome', 'LIKE', "%{$term}%");
                }
            })
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($item) use ($query) {
                $item->search_score = $this->calculateMatchScore($item, $query);

                return $item;
            });
    }

    /**
     * Normalize scores across results.
     *
     * @param  Collection<int, \App\Models\Memory>  $results
     * @return Collection<int, \App\Models\Memory>
     */
    private function normalizeScores(Collection $results, string $query): Collection
    {
        if ($results->isEmpty()) {
            return $results;
        }

        $maxScore = $results->max('search_score') ?? 1.0;

        return $results->map(function ($item) use ($maxScore) {
            $item->search_score = $maxScore > 0 ? $item->search_score / $maxScore : 0.0;

            return $item;
        });
    }

    /**
     * Calculate a match score based on term frequency.
     */
    private function calculateMatchScore(Memory $item, string $query): float
    {
        $text = strtolower($item->content.' '.$item->outcome);
        $terms = $this->tokenize($query);

        if (empty($terms)) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($terms as $term) {
            $score += substr_count($text, $term);
        }

        return $score / count($terms);
    }

    /**
     * Tokenize a query string.
     *
     * @return array<int, string>
     */
    private function tokenize(string $query): array
    {
        return array_filter(
            preg_split('/\s+/',
                strtolower(
                    preg_replace('/[^a-z0-9\s]/', ' ', $query)
                )
            ),
            fn ($w) => strlen($w) > 1
        );
    }
}
