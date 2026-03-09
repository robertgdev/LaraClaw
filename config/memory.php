<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search Strategy
    |--------------------------------------------------------------------------
    |
    | When using Scout's 'database' driver, you can choose to use native
    | database full-text search features (MySQL FULLTEXT, PostgreSQL FTS)
    | instead of Scout's simple LIKE-based search.
    |
    | Set to true to use native database full-text features.
    |
    */
    'use_native_fulltext' => env('MEMORY_NATIVE_FULLTEXT', false),

    /*
    |--------------------------------------------------------------------------
    | Scoring Weights
    |--------------------------------------------------------------------------
    |
    | Weights for the hybrid scoring formula:
    | relevance = (fts × fts_weight) + (temporal × temporal_weight) + (importance × importance_weight)
    |
    | The weights should sum to 1.0 for proper normalization.
    |
    */
    'scoring' => [
        'fts_weight' => env('MEMORY_FTS_WEIGHT', 0.4),
        'temporal_weight' => env('MEMORY_TEMPORAL_WEIGHT', 0.3),
        'importance_weight' => env('MEMORY_IMPORTANCE_WEIGHT', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporal Decay
    |--------------------------------------------------------------------------
    |
    | Ebbinghaus forgetting curve parameters for temporal scoring.
    |
    | Formula: e^(-rate × days_since_access) × (1 + bonus × access_count)
    |
    | - rate: How quickly memories decay (higher = faster decay)
    | - access_bonus: How much each access strengthens the memory
    | - min_importance: Minimum importance threshold before pruning
    |
    */
    'decay' => [
        'rate' => env('MEMORY_DECAY_RATE', 0.05),
        'access_bonus' => env('MEMORY_ACCESS_BONUS', 0.02),
        'min_importance' => env('MEMORY_MIN_IMPORTANCE', 0.05),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consolidation
    |--------------------------------------------------------------------------
    |
    | Parameters for memory consolidation (decay, prune, merge).
    |
    | - decay_after_days: Days without access before importance decays
    | - decay_factor: Multiplier for importance decay (0.95 = 5% reduction)
    | - prune_after_days: Days before low-value memories are deleted
    | - prune_max_importance: Maximum importance for pruning eligibility
    | - merge_similarity_threshold: Jaccard similarity threshold for merging
    | - merge_check_limit: Maximum memories to check for duplicates
    |
    */
    'consolidation' => [
        'decay_after_days' => env('MEMORY_DECAY_AFTER_DAYS', 7),
        'decay_factor' => env('MEMORY_DECAY_FACTOR', 0.95),
        'prune_after_days' => env('MEMORY_PRUNE_AFTER_DAYS', 30),
        'prune_max_importance' => env('MEMORY_PRUNE_MAX_IMPORTANCE', 0.1),
        'merge_similarity_threshold' => env('MEMORY_MERGE_THRESHOLD', 0.8),
        'merge_check_limit' => env('MEMORY_MERGE_LIMIT', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Limits
    |--------------------------------------------------------------------------
    |
    | Limits for search operations.
    |
    */
    'search' => [
        'fts_max_results' => env('MEMORY_FTS_MAX_RESULTS', 50),
        'context_max_results' => env('MEMORY_CONTEXT_MAX_RESULTS', 10),
    ],

];
