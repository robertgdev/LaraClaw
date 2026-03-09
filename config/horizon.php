<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | Horizon uses Redis for queue management. You may configure the Redis
    | connection options here. This connection will be used by Horizon
    | to manage its queues and process jobs.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may change this to avoid conflicts with other applications using
    | the same Redis instance.
    |
    */

    'prefix' => env('HORIZON_PREFIX', 'laraclaw:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto every Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Horizon's workers are configured here. Each worker defines the queues
    | it should process and the number of processes it should spawn.
    |
    | LaraClaw supports three queue strategies:
    |
    | 1. single: All messages go to 'default' queue
    |    - Simple setup, no ordering guarantee
    |    - Set LARACLAW_QUEUE_STRATEGY=single
    |
    | 2. per_agent: Each agent gets its own queue (agent-{id})
    |    - Guaranteed ordering with 1 worker per queue
    |    - Set LARACLAW_QUEUE_STRATEGY=per_agent
    |
    | 3. priority: Agents grouped into priority tiers
    |    - Each agent still gets own queue for ordering
    |    - Set LARACLAW_QUEUE_STRATEGY=priority
    |
    | IMPORTANT: Ordering is ONLY guaranteed when each queue has exactly ONE
    | worker. Multiple workers on the same queue can process messages out of
    | order, which breaks conversation context for agents.
    |
    */

    'environments' => [
        /*
        |----------------------------------------------------------------------
        | Production Environment
        |----------------------------------------------------------------------
        |
        | For production, use the strategy configured in LARACLAW_QUEUE_STRATEGY.
        | The default is 'single' for simplicity.
        |
        */
        'production' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'maxProcesses' => env('LARACLAW_SINGLE_MAX_PROCESSES', 5),
                'minProcesses' => 1,
                'tries' => env('LARACLAW_QUEUE_JOB_TRIES', 3),
                'timeout' => env('LARACLAW_QUEUE_JOB_TIMEOUT', 300),
                'retryAfter' => env('LARACLAW_QUEUE_JOB_RETRY_AFTER', 60),
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Per-Agent Environment
        |----------------------------------------------------------------------
        |
        | Use this when LARACLAW_QUEUE_STRATEGY=per_agent
        | Each agent gets its own queue with 1 worker for ordering guarantee.
        |
        | To use: Set APP_ENV=per_agent and configure your agents below.
        | Note: You need to manually add a supervisor for each agent.
        |
        | Example for agents 'coder' and 'writer':
        |
        | 'per_agent' => [
        |     'supervisor-coder' => [
        |         'connection' => 'redis',
        |         'queue' => ['agent-coder'],
        |         'balance' => 'simple',
        |         'processes' => 1,  // MUST be 1 for ordering guarantee
        |         'tries' => 3,
        |         'timeout' => 300,
        |     ],
        |     'supervisor-writer' => [
        |         'connection' => 'redis',
        |         'queue' => ['agent-writer'],
        |         'balance' => 'simple',
        |         'processes' => 1,
        |         'tries' => 3,
        |         'timeout' => 300,
        |     ],
        | ],
        |
        */
        'per_agent' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['agent-default'],
                'balance' => 'simple',
                'processes' => 1,
                'tries' => 3,
                'timeout' => 300,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Priority Environment
        |----------------------------------------------------------------------
        |
        | Use this when LARACLAW_QUEUE_STRATEGY=priority
        | Agents are grouped into priority tiers. Each agent still gets its own
        | queue for ordering guarantee.
        |
        | To use: Set APP_ENV=priority and configure priority_tiers in laraclaw.php
        |
        | Example configuration:
        |
        | 'priority' => [
        |     'supervisor-high' => [
        |         'connection' => 'redis',
        |         'queue' => ['agent-coder', 'agent-assistant'],
        |         'balance' => 'auto',
        |         'maxProcesses' => 5,
        |         'processes' => 2,  // 1 per queue for ordering
        |         'tries' => 3,
        |         'timeout' => 300,
        |     ],
        |     'supervisor-default' => [
        |         'connection' => 'redis',
        |         'queue' => ['agent-writer', 'agent-researcher'],
        |         'balance' => 'auto',
        |         'maxProcesses' => 3,
        |         'processes' => 2,
        |         'tries' => 3,
        |         'timeout' => 300,
        |     ],
        | ],
        |
        */
        'priority' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 300,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Local Development Environment
        |----------------------------------------------------------------------
        |
        | Simple configuration for local development. Uses single queue strategy.
        |
        */
        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'minProcesses' => 1,
                'tries' => 3,
                'timeout' => 300,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Authentication
    |--------------------------------------------------------------------------
    |
    | This configuration option defines the authentication guards that will
    | be used to access the Horizon dashboard. You may change these to
    | any guards you prefer for your application.
    |
    */

    'guards' => [
        'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Authorization
    |--------------------------------------------------------------------------
    |
    | This closure is used to authorize access to the Horizon dashboard.
    | You may customize this closure as needed to restrict access to
    | Horizon in your production environment.
    |
    */

    'allowed_environments' => ['local', 'testing', 'production'],

    /*
    |--------------------------------------------------------------------------
    | Horizon Memory Limit
    |--------------------------------------------------------------------------
    |
    | This value defines the maximum amount of memory (in MB) that each
    | Horizon worker process may consume before it is terminated.
    |
    */

    'memory_limit' => env('HORIZON_MEMORY_LIMIT', 512),

    /*
    |--------------------------------------------------------------------------
    | Horizon Waits
    |--------------------------------------------------------------------------
    |
    | This value defines how many seconds Horizon should wait before retrying
    | a job that has failed. You may configure this value as needed.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Metrics
    |--------------------------------------------------------------------------
    |
    | Horizon can collect metrics about your queues. You may configure the
    | time window for these metrics here.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24 * 60,
            'queue' => 24 * 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon will terminate all workers when
    | it receives a termination signal. This is useful for deployments.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Horizon Output
    |--------------------------------------------------------------------------
    |
    | This option defines where Horizon should send its output. By default,
    | Horizon will send its output to the standard output stream.
    |
    */

    'output' => null,
];
