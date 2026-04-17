<?php

namespace ManticoreLaravel\Exceptions;

class ManticoreConnectionException extends \RuntimeException
{
    public static function connectionNotFound(string $connection, array $available = []): static
    {
        $message = "Manticore connection [{$connection}] is not defined.";

        if ($available !== []) {
            $message .= ' Available connections: ' . implode(', ', $available) . '.';
        }

        return new static($message);
    }

    public static function invalidConfig(string $connection): static
    {
        return new static("Manticore connection [{$connection}] must be configured as an array.");
    }
}
