<?php

namespace ManticoreLaravel\Contracts;

interface ConnectionResolverContract
{
    /**
     * Resolve the connection configuration array.
     *
     * @param  string|null  $connection
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int, limit_results: int}
     */
    public function resolve(?string $connection = null): array;

    /**
     * @return array<int, string>
     */
    public function availableConnections(): array;
}
