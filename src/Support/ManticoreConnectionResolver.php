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

        if ($connection !== null) {
            if (!isset($connections[$connection])) {
                throw new InvalidArgumentException(
                    "Manticore connection [{$connection}] is not defined."
                );
            }

            return $this->normalize($connections[$connection]);
        }

        if (!empty($connections)) {
            $defaultName = $this->config->get('manticore.default', 'default');

            if (isset($connections[$defaultName])) {
                return $this->normalize($connections[$defaultName]);
            }
        }

        return $this->normalize($this->resolveLegacyConfig());
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
     * Normalize a raw connection array, filling in safe defaults for any
     * missing keys so callers always receive a complete, typed structure.
     */
    private function normalize(array $config): array
    {
        return [
            'host'        => $config['host']        ?? '127.0.0.1',
            'port'        => $config['port']        ?? 9312,
            'username'    => $config['username']    ?? null,
            'password'    => $config['password']    ?? null,
            'transport'   => $config['transport']   ?? 'Http',
            'timeout'     => $config['timeout']     ?? 5,
            'persistent'  => $config['persistent']  ?? false,
            'max_matches' => $config['max_matches'] ?? 1000,
        ];
    }
}
