<?php

namespace ManticoreLaravel\Exceptions;

class ManticoreIndexNotFoundException extends ManticoreQueryException
{
    public static function forIndex(string $indexName): static
    {
        return new static("Manticore index [{$indexName}] was not found.", '');
    }
}
