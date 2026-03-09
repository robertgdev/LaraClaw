<?php

namespace App\Services\Memory;

/**
 * Factory for creating search strategy instances.
 *
 * Automatically selects the appropriate strategy based on configuration:
 * - Scout with Meilisearch/Typesense/Algolia → ScoutSearchStrategy
 * - Scout with database driver → ScoutSearchStrategy (LIKE-based)
 * - Scout with database + native_fulltext → DatabaseSearchStrategy (MySQL FULLTEXT, etc.)
 */
class SearchStrategyFactory
{
    /**
     * Create the appropriate search strategy based on configuration.
     */
    public static function create(): SearchStrategyInterface
    {
        $scoutDriver = config('scout.driver');
        $useNativeFulltext = config('memory.use_native_fulltext', false);

        // If Scout is configured with a real search engine, use Scout strategy
        if (in_array($scoutDriver, ['meilisearch', 'algolia', 'typesense'])) {
            return app(ScoutSearchStrategy::class);
        }

        // If Scout is using database driver, we have two options:
        // 1. Use Scout's database driver (simple LIKE)
        // 2. Use our direct database strategy (MySQL FULLTEXT, PostgreSQL FTS, etc.)
        if ($scoutDriver === 'database') {
            if ($useNativeFulltext) {
                return app(DatabaseSearchStrategy::class);
            }

            return app(ScoutSearchStrategy::class);
        }

        // If Scout is using collection driver (for testing), use Scout strategy
        if ($scoutDriver === 'collection') {
            return app(ScoutSearchStrategy::class);
        }

        // Default to Scout strategy
        return app(ScoutSearchStrategy::class);
    }

    /**
     * Get the name of the strategy that would be used.
     */
    public static function getStrategyName(): string
    {
        return self::create()->getName();
    }
}
