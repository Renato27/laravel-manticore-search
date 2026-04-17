<?php

namespace ManticoreLaravel\Exceptions;

class ManticoreQueryException extends \RuntimeException
{
    private string $failedSql;

    public function __construct(string $message, string $failedSql = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->failedSql = $failedSql;
        parent::__construct($message, $code, $previous);
    }

    public static function fromQuery(string $sql, \Throwable $previous): static
    {
        return new static(
            "Manticore query failed: {$previous->getMessage()}",
            $sql,
            (int) $previous->getCode(),
            $previous
        );
    }

    public function getFailedSql(): string
    {
        return $this->failedSql;
    }
}
