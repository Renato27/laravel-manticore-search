<?php

namespace ManticoreLaravel\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ManticoreLaravel\Contracts\ConnectionResolverContract;
use ManticoreLaravel\Exceptions\ManticoreConnectionException;

class ManticoreConnectionResolver implements ConnectionResolverContract
{
    public function __construct(protected ConfigRepository $config) {}

    /**
     * @return array<int, string>
     */
    public function availableConnections(): array
    {
        $connections = $this->config->get('manticore.connections', []);

        return is_array($connections) ? array_keys($connections) : [];
    }

    /**
     * Resolve the connection configuration array.
     *
     * @param  string|null  $connection
     * @return array
     *
     * @throws ManticoreConnectionException
     */
    public function resolve(?string $connection = null): array
    {
        $connections = $this->config->get('manticore.connections', []);
        $connections = is_array($connections) ? $connections : [];

        // Explicit connection name requested
        if ($connection !== null) {
            if (isset($connections[$connection])) {
                return $this->normalizeConnectionConfig($connections[$connection], $connection);
            }

            if ($connections === []) {
                return $this->normalizeConnectionConfig($this->resolveLegacyConfig(), 'legacy');
            }

            throw ManticoreConnectionException::connectionNotFound($connection, array_keys($connections));
        }

        if (!empty($connections)) {
            $defaultName = $this->config->get('manticore.default', 'default');

            if (isset($connections[$defaultName])) {
                return $this->normalizeConnectionConfig($connections[$defaultName], $defaultName);
            }
        }

        return $this->normalizeConnectionConfig($this->resolveLegacyConfig(), 'legacy');
    }

    /**
     * Build a config array from the legacy top-level keys.
     * Supports existing published config files that predate multi-connection support.
     */
    private function resolveLegacyConfig(): array
    {
        return [
            'host'           => $this->config->get('manticore.host',           '127.0.0.1'),
            'port'           => $this->config->get('manticore.port',           9312),
            'username'       => $this->config->get('manticore.username',       null),
            'password'       => $this->config->get('manticore.password',       null),
            'transport'      => $this->config->get('manticore.transport',      'Http'),
            'timeout'        => $this->config->get('manticore.timeout',        5),
            'persistent'     => $this->config->get('manticore.persistent',     false),
            'max_matches'    => $this->config->get('manticore.max_matches',    1000),
            'limit_results'  => $this->config->get('manticore.limit_results',  0),
        ];
    }

    /**
     * @param  mixed  $config
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int, limit_results: int}
     *
     * @throws ManticoreConnectionException
     */
    private function normalizeConnectionConfig(mixed $config, string $connectionName): array
    {
        if (!is_array($config)) {
            throw ManticoreConnectionException::invalidConfig($connectionName);
        }

        return $this->normalize($config);
    }

    private function normalize(array $config): array
    {
        return [
            'host'          => (string) ($config['host']          ?? '127.0.0.1'),
            'port'          => (int)    ($config['port']          ?? 9312),
            'username'      => isset($config['username'])  ? (string) $config['username']  : null,
            'password'      => isset($config['password'])  ? (string) $config['password']  : null,
            'transport'     => (string) ($config['transport']     ?? 'Http'),
            'timeout'       => (int)    ($config['timeout']       ?? 5),
            'persistent'    => (bool)   ($config['persistent']    ?? false),
            'max_matches'   => (int)    ($config['max_matches']   ?? 1000),
            'limit_results' => (int)    ($config['limit_results'] ?? 0),
        ];
    }
}
