<?php

namespace ManticoreLaravel\Support;

use Manticoresearch\Client;
use Manticoresearch\Table;

/**
 * Central orchestration point for resolved connection configuration and client instances.
 *
 * This keeps config resolution and client lifecycle concerns out of query builders while
 * preserving backward-compatible runtime behavior.
 */
class ManticoreManager
{
    /**
     * @var array<string, Client>
     */
    private array $clients = [];

    public function __construct(
        protected ManticoreConnectionResolver $resolver,
        protected ManticoreClientFactory $factory,
    ) {}

    /**
     * Resolve a normalized connection config.
     *
     * @param  string|null  $connection
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int}
     */
    public function resolveConfig(?string $connection = null): array
    {
        return $this->resolver->resolve($connection);
    }

    /**
     * Resolve or reuse a Manticore client for the given connection.
     */
    public function client(?string $connection = null): Client
    {
        $config = $this->resolveConfig($connection);
        $cacheKey = ($connection ?? '__default__').'|'.$this->factory->cacheKey($config);

        if (!isset($this->clients[$cacheKey])) {
            $this->clients[$cacheKey] = $this->factory->make($config);
        }

        return $this->clients[$cacheKey];
    }

    /**
     * @return array<int, string>
     */
    public function connectionNames(): array
    {
        return $this->resolver->availableConnections();
    }

    /**
     * Create a table instance for a specific index using the selected connection.
     */
    public function table(array|string $index, ?string $connection = null): Table
    {
        $table = new Table($this->client($connection));
        $table->setName(is_array($index) ? implode(',', $index) : $index);

        return $table;
    }

    /**
     * Forget a cached client for a given connection or clear all cached clients.
     */
    public function forgetClient(?string $connection = null): void
    {
        if ($connection === null) {
            $this->clients = [];

            return;
        }

        foreach (array_keys($this->clients) as $cacheKey) {
            if (str_starts_with($cacheKey, $connection.'|')) {
                unset($this->clients[$cacheKey]);
            }
        }
    }
}