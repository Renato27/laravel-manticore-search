<?php

namespace ManticoreLaravel\Contracts;

interface ConnectionResolverContract
{
    /**
     * Resolve the connection configuration array.
     *
     * @param  string|null  $connection
     * @return array
     */
    public function resolve(?string $connection = null): array;

    /**
     * @return array<int, string>
     */
    public function availableConnections(): array;
}
