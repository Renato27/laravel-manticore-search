<?php

namespace ManticoreLaravel\Traits;

use ManticoreLaravel\Builder\ManticoreBuilder;
use RuntimeException;

trait HasManticoreSearch
{
    /**
     * Create a fluent Manticore query builder for the current model.
     *
     * The model must implement `searchableAs()` and return the target index name
     * or an array of index names.
     */
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
