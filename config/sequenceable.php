<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Table Name
    |--------------------------------------------------------------------------
    |
    | Define the database table where sequence values are stored.
    |
    */
    'table' => 'sequences',

    /*
    |--------------------------------------------------------------------------
    | Database Recycled Table Name
    |--------------------------------------------------------------------------
    |
    | Define the database table where recycled sequence numbers are stored.
    |
    */
    'recycled_table' => 'sequence_recycled',

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Define the database connection to be used.
    | Set to null to use the default application connection.
    |
    */
    'connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Concurrency Locking Configuration
    |--------------------------------------------------------------------------
    |
    | Prevent duplicate sequence generation in high-concurrency environments.
    |
    | Drivers:
    |   - 'database': Pessimistic locking (SELECT FOR UPDATE) on the sequence row.
    |   - 'cache': Atomic lock via Cache facade (e.g. Redis, Memcached).
    |   - 'none': Disable concurrency protection (not recommended for production).
    |
    */
    'locking' => [
        'driver' => 'database',
        'cache_store' => null,
        'timeout' => 5, // Seconds to wait for the lock
        'retry_interval' => 100, // Milliseconds between retry attempts
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Mode
    |--------------------------------------------------------------------------
    |
    | Choose how the sequence database update interacts with model transactions.
    |
    | Modes:
    |   - 'gapless': Sequence increments within the model's database transaction.
    |                If model creation fails or rolls back, the sequence counter
    |                is also rolled back. This prevents sequence gaps but keeps
    |                database locks longer, which can bottleneck high-throughput systems.
    |   - 'gap_tolerant': Sequence increments in an isolated, immediate transaction.
    |                     It never rolls back even if the model fails to save.
    |                     This eliminates locking bottlenecks but can result in gaps.
    |
    */
    'transaction_mode' => 'gapless',

    /*
    |--------------------------------------------------------------------------
    | High-Performance Pre-Allocation (Hi/Lo Algorithm)
    |--------------------------------------------------------------------------
    |
    | Instead of hitting the database for every single sequence increment,
    | the package can fetch a batch of numbers (a "block") and increment
    | them in-memory (using the cache store). Highly recommended for flash sales,
    | high-volume API endpoints, or bulk imports to prevent DB lock queues.
    |
    */
    'pre_allocation' => [
        'enabled' => false,
        'block_size' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Tracking
    |--------------------------------------------------------------------------
    |
    | Track which user triggered the creation or update of a sequence partition.
    | If enabled, migrations will include created_by and updated_by columns,
    | and the sequence manager will populate them with the authenticated user ID.
    |
    | Supported user_id_types: 'bigInteger', 'uuid', 'ulid', 'string'
    |
    */
    'audit' => [
        'enabled' => false,
        'user_model' => 'App\Models\User',
        'created_by_column' => 'created_by',
        'updated_by_column' => 'updated_by',
        'user_id_type' => 'bigInteger',
    ],
];
