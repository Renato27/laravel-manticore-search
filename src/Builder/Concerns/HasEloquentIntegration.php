<?php

namespace ManticoreLaravel\Builder\Concerns;

use Illuminate\Database\Eloquent\Collection;

/**
 * Handles eager loading of Eloquent relations on Manticore result sets.
 *
 * Properties referenced here are declared in ManticoreBuilderAbstract.
 */
trait HasEloquentIntegration
{
    protected function applyEloquentWith(Collection $items): Collection
    {
        if ($items->isEmpty() || empty($this->eagerQueue)) {
            return $items;
        }

        $load = [];
        $seen = [];

        foreach ($this->eagerQueue as $entry) {
            $name = $entry['name'];

            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            if ($entry['closure'] instanceof \Closure) {
                $load[$name] = $entry['closure'];
            } else {
                $load[] = $name;
            }
        }

        if (!empty($load)) {
            $items->load($load);
        }

        return $items;
    }
}
