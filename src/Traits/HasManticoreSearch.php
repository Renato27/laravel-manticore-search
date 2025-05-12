<?php

namespace ManticoreLaravel\Traits;

use ManticoreLaravel\Builder\ManticoreBuilder;
use RuntimeException;

trait HasManticoreSearch
{
    public static function manticore(): ManticoreBuilder
    {
        $instance = new static;

        if (!method_exists($instance, 'searchableAs')) {
            throw new RuntimeException(sprintf(
                'The %s model must implement the searchableAs() method.',
                static::class
            ));
        }

        return new ManticoreBuilder($instance);
    }
}
