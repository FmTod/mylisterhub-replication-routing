<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Replication Routing Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch to enable/disable all replication routing logic.
    | When disabled, Laravel's default read/write connection behavior is used.
    |
    */
    'enabled' => env('DB_REPLICATION_ROUTING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Connections to Monitor
    |--------------------------------------------------------------------------
    |
    | List of database connection names that should be monitored for
    | replication lag and use write fences. Only connections with read/write
    | separation configured will benefit from this package.
    |
    */
    'connections' => array_filter(explode(',', env('DB_REPLICATION_CONNECTIONS', 'mysql,mariadb'))),

    /*
    |--------------------------------------------------------------------------
    | Lag Threshold Configuration
    |--------------------------------------------------------------------------
    |
    | Global safety net: monitors replica lag and routes all reads to master
    | when any replica falls behind the threshold.
    |
    */
    'lag_threshold' => [
        'enabled' => env('DB_CHECK_REPLICATION_LAG', true),
        'threshold' => env('DB_REPLICATION_LAG_THRESHOLD', 1), // seconds
        'check_interval' => 60, // seconds between lag checks (cached)
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Fences Configuration
    |--------------------------------------------------------------------------
    |
    | Per-user/tenant read-your-writes guarantee using GTID/LSN tracking.
    | More precise than lag threshold, provides better UX.
    |
    */
    'write_fences' => [
        'enabled' => env('DB_WRITE_FENCES_ENABLED', false),

        // Hot flag TTL: Fast path routing after writes (seconds)
        'hot_ttl' => env('DB_WRITE_FENCE_HOT_TTL', 2),

        // Fence TTL: How long to keep position tracking (seconds)
        'fence_ttl' => env('DB_WRITE_FENCE_TTL', 600),

        // Track writes per user
        'track_user' => env('DB_WRITE_FENCE_TRACK_USER', true),

        // Track writes per tenant (if using multi-tenancy)
        'track_tenant' => env('DB_WRITE_FENCE_TRACK_TENANT', true),

        // Table filtering: Only track writes to specific tables (empty = track all)
        'table_whitelist' => array_filter(explode(',', env('DB_WRITE_FENCE_TABLE_WHITELIST', ''))),

        // Table filtering: Ignore writes to specific tables
        'table_blacklist' => array_filter(explode(',', env('DB_WRITE_FENCE_TABLE_BLACKLIST', 'migrations,jobs,failed_jobs,sessions,cache,pulse_entries,pulse_aggregates,pulse_values'))),
    ],
];
