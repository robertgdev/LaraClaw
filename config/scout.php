<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default search driver that will be used by
    | Laravel Scout. You may change this to any driver that is installed.
    |
    | Supported: "algolia", "meilisearch", "database", "collection", "typesense"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search indexes
    | used by Scout. This prefix may be useful if you share the same search
    | engine with other applications.
    |
    */

    'prefix' => env('SCOUT_PREFIX', 'laraclaw_'),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true", then
    | all of the data syncing operations will be queued for better performance.
    |
    */

    'queue' => [
        'enable' => env('SCOUT_QUEUE', false),
        'connection' => env('SCOUT_QUEUE_CONNECTION', null),
        'queue' => env('SCOUT_QUEUE_NAME', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will be synced with your
    | search indexes after every commit, instead of after every save. This may
    | improve performance but will also delay the syncing of your data.
    |
    */

    'after_commit' => false,

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option determines if Scout will preserve soft deleted records in the
    | search indexes. When this is set to true, Scout will not remove records
    | from the search index when they are soft deleted.
    |
    */

    'soft_delete' => false,

    /*
    |--------------------------------------------------------------------------
    | Identify Users
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Scout will identify the current user when
    | performing operations on search indexes. This is useful for analytics.
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY', null),
        'index-settings' => [
            // 'users' => [
            //     'filterableAttributes' => ['name', 'email'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', ''),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT', 1),
        ],
        'model-settings' => [
            // User::class => [
            //     'collection-schema' => [
            //         'fields' => [
            //             ['name' => 'id', 'type' => 'string'],
            //             ['name' => 'name', 'type' => 'string'],
            //         ],
            //     ],
            // ],
        ],
    ],
];
