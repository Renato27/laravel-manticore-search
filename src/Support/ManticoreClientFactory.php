<?php

namespace ManticoreLaravel\Support;

use ManticoreLaravel\Builder\Utils\Utf8SafeClient;

class ManticoreClientFactory
{
    /**
     * Build a Utf8SafeClient from a resolved connection config array.
     *
     * @param  array
     */
    public function make(array $config): Utf8SafeClient
    {
        return new Utf8SafeClient([
            'host'       => $config['host'],
            'port'       => $config['port'],
            'username'   => $config['username'],
            'password'   => $config['password'],
            'transport'  => $config['transport'],
            'timeout'    => $config['timeout'],
            'persistent' => $config['persistent'],
        ]);
    }

    /**
     * Build a deterministic cache key for a normalized connection config.
     *
     * @param  array
     */
    public function cacheKey(array $config): string
    {
        return md5(json_encode($config, JSON_THROW_ON_ERROR));
    }
}
