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
];