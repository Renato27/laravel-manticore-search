<?php

return [
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9312),
    'password' => env('MANTICORE_PASSWORD', null),
    'username' => env('MANTICORE_USERNAME', null),
    'transport' => env('MANTICORE_TRANSPORT', 'Http'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'persistent' => env('MANTICORE_PERSISTENT', false),
    'max_matches' => env('MANTICORE_MAX_MATCHES', 1000),
];
