<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Manticore Connection Name
    |--------------------------------------------------------------------------
    |
    | This option controls the default Manticore connection that will be used
    | by the library unless another connection name is explicitly provided.
    |
    */

    'default' => env('MANTICORE_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Manticore Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the Manticore connections for your
    | application. Of course, examples of configuring each supported
    | transport are shown below.
    |
    */

    'connections' => [

        'default' => [
            'host' => env('MANTICORE_HOST', '127.0.0.1'),
            'port' => env('MANTICORE_PORT', 9312),
            'password' => env('MANTICORE_PASSWORD', null),
            'username' => env('MANTICORE_USERNAME', null),
            'transport' => env('MANTICORE_TRANSPORT', 'Http'),
            'timeout' => env('MANTICORE_TIMEOUT', 5),
            'persistent' => env('MANTICORE_PERSISTENT', false),
            'max_matches' => env('MANTICORE_MAX_MATCHES', 1000),
            'limit_results' => env('MANTICORE_LIMIT_RESULTS', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Flat Configuration
    |--------------------------------------------------------------------------
    |
    | These options are kept for backward compatibility with previous
    | versions of the package. New applications should prefer using
    | the "default" and "connections" configuration structure above.
    |
    */

    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9312),
    'password' => env('MANTICORE_PASSWORD', null),
    'username' => env('MANTICORE_USERNAME', null),
    'transport' => env('MANTICORE_TRANSPORT', 'Http'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'persistent' => env('MANTICORE_PERSISTENT', false),
    'max_matches' => env('MANTICORE_MAX_MATCHES', 1000),
    'limit_results' => env('MANTICORE_LIMIT_RESULTS', false),
    'unlimited_max_matches' => env('MANTICORE_UNLIMITED_MAX_MATCHES', 1000000),
    /*
    |--------------------------------------------------------------------------
    | Pagination Context for Large Filter Payloads
    |--------------------------------------------------------------------------
    |
    | When filter payloads become too large for safe URL query strings, the
    | builder stores filters in cache and uses a short context token on
    | pagination links.
    |
    | total_cache_ttl: Cache duration for pagination totals (in seconds).
    | Set this to cache the total count across pagination requests.
    |
    */

    'pagination' => [
        'context_key' => env('MANTICORE_PAGINATION_CONTEXT_KEY', '_mctx'),
        'max_query_length' => env('MANTICORE_PAGINATION_MAX_QUERY_LENGTH', 1500),
        'context_ttl' => env('MANTICORE_PAGINATION_CONTEXT_TTL', 900),
        'cache_prefix' => env('MANTICORE_PAGINATION_CACHE_PREFIX', 'manticore:pagination:'),
        'total_cache_ttl' => env('MANTICORE_PAGINATION_TOTAL_CACHE_TTL', 300), // 5 minutes
    ],
];