<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Entities (Allowlist)
    |--------------------------------------------------------------------------
    |
    | Only classes listed here can be resolved as callables.
    | Prevents RCE via arbitrary class instantiation through app().
    |
    | Supports two formats:
    |   - Simple string: \App\Services\MyService::class
    |   - Keyed array:   \App\Services\MyService::class => ['queue' => 'heavy', 'connection' => 'redis', 'timeout' => 600]
    |
    | Both formats can be mixed in the same array.
    |
    */
    'allowed_entities' => [
        // \App\Services\MyService::class,
        // \App\Services\HeavyService::class => ['queue' => 'heavy', 'timeout' => 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Attempts
    |--------------------------------------------------------------------------
    |
    | Maximum number of retry attempts for a process before marking as error.
    |
    */
    'default_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Clear After Days
    |--------------------------------------------------------------------------
    |
    | Terminal processes older than this number of days will be removed
    | by the delayed:clear command.
    |
    */
    'clear_after_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Stuck Timeout (minutes)
    |--------------------------------------------------------------------------
    |
    | Processes in "wait" status longer than this are considered stuck
    | and can be reset by the delayed:unstuck command.
    |
    */
    'stuck_timeout_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Log Sensitive Context
    |--------------------------------------------------------------------------
    |
    | When false, the context array from log events is stripped
    | to prevent secrets from leaking into the database.
    |
    */
    'log_sensitive_context' => false,

    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    */
    'job' => [
        'timeout' => 300,
        'tries' => 1,
        'backoff' => [30, 60, 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Configuration
    |--------------------------------------------------------------------------
    */
    'command' => [
        'sleep' => 5,
        'max_iterations' => 0, // 0 = infinite
        'throttle' => 100_000, // microseconds between iterations
    ],

    /*
    |--------------------------------------------------------------------------
    | Clear Command Chunk Size
    |--------------------------------------------------------------------------
    */
    'clear_chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Log Buffer Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of log entries kept in memory buffer per process.
    | When exceeded, oldest entries are discarded (FIFO). 0 = unlimited.
    |
    */
    'log_buffer_limit' => 500,

    /*
    |--------------------------------------------------------------------------
    | Callback / Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled and a process has a callback_url, an HTTP POST
    | will be sent on terminal status (done, error, expired, cancelled).
    |
    */
    'callback' => [
        'enabled' => false,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Default time-to-live for new processes. Null = no expiration.
    | Expired processes are marked by the delayed:expire command.
    |
    */
    'default_ttl_minutes' => null,

];
