<?php

namespace ManticoreLaravel\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * Resolves the active Manticore connection configuration.
 *
 */
class ManticoreConnectionResolver
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
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int}
     *
     * @throws InvalidArgumentException
     */
    public function resolve(?string $connection = null): array
    {
        $connections = $this->config->get('manticore.connections', []);
        $connections = is_array($connections) ? $connections : [];

        if ($connection !== null) {
            if (isset($connections[$connection])) {
                return $this->normalizeConnectionConfig($connections[$connection], $connection);
            }

            if ($connections === []) {
                return $this->normalizeConnectionConfig($this->resolveLegacyConfig(), 'legacy');
            }

            if (!isset($connections[$connection])) {
                $this->throwUndefinedConnection($connection, array_keys($connections));
            }
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
            'host'        => $this->config->get('manticore.host',        '127.0.0.1'),
            'port'        => $this->config->get('manticore.port',        9312),
            'username'    => $this->config->get('manticore.username',    null),
            'password'    => $this->config->get('manticore.password',    null),
            'transport'   => $this->config->get('manticore.transport',   'Http'),
            'timeout'     => $this->config->get('manticore.timeout',     5),
            'persistent'  => $this->config->get('manticore.persistent',  false),
            'max_matches' => $this->config->get('manticore.max_matches', 1000),
        ];
    }

    /**
     * @param  mixed  $config
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int}
     */
    private function normalizeConnectionConfig(mixed $config, string $connectionName): array
    {
        if (!is_array($config)) {
            throw new InvalidArgumentException(
                "Manticore connection [{$connectionName}] must be configured as an array."
            );
        }

        return $this->normalize($config);
    }

    /**
     * Normalize a raw connection array, filling in safe defaults for any
     * missing keys so callers always receive a complete, typed structure.
     */
    private function normalize(array $config): array
    {
        return [
            'host'        => (string) ($config['host']        ?? '127.0.0.1'),
            'port'        => (int) ($config['port']           ?? 9312),
            'username'    => isset($config['username']) ? (string) $config['username'] : null,
            'password'    => isset($config['password']) ? (string) $config['password'] : null,
            'transport'   => (string) ($config['transport']   ?? 'Http'),
            'timeout'     => (int) ($config['timeout']        ?? 5),
            'persistent'  => (bool) ($config['persistent']    ?? false),
            'max_matches' => (int) ($config['max_matches']    ?? 1000),
        ];
    }

    /**
     * @param  array<int, string>  $availableConnections
     */
    private function throwUndefinedConnection(string $connection, array $availableConnections): never
    {
        $message = "Manticore connection [{$connection}] is not defined.";

        if ($availableConnections !== []) {
            $message .= ' Available connections: '.implode(', ', $availableConnections).'.';
        }

        throw new InvalidArgumentException($message);
    }
}
