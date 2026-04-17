<?php

namespace ManticoreLaravel\Builder\Concerns;

use Manticoresearch\Query\Equals;
use Manticoresearch\Query\In;
use Manticoresearch\Query\Range;

/**
 * Provides where/filter constraint methods for the Manticore builder.
 *
 * Properties referenced here are declared in ManticoreBuilderAbstract.
 */
trait HasQueryConstraints
{
    /**
     * Parse 2-arg or 3-arg where signatures into [operator, value].
     *
     * Must be `protected` so subclasses (ManticoreBuilder extends ManticoreBuilderAbstract)
     * can call it — `private` trait methods are NOT inherited by child classes in PHP.
     *
     * @return array{0: string, 1: mixed}
     */
    protected function parseWhereArgs(int $numArgs, mixed $operatorOrValue, mixed $value): array
    {
        if ($numArgs === 2) {
            return ['=', $operatorOrValue];
        }

        return [(string) $operatorOrValue, $value];
    }

    /**
     * Register a condition in both the bool arrays (for Search API) and whereSequence (for SQL).
     *
     * Must be `protected` — same reason as parseWhereArgs.
     */
    protected function addCondition(mixed $filter, string $boolean, bool $negated): void
    {
        $this->whereSequence[] = [
            'boolean'   => $boolean,
            'negated'   => $negated,
            'condition' => $filter,
        ];

        if ($negated) {
            $this->mustNot[] = $filter;
            return;
        }

        if ($boolean === 'or') {
            // Promote last AND condition to should on first OR call
            if (!empty($this->must) && empty($this->should)) {
                $this->should[] = array_pop($this->must);
            }
            $this->should[] = $filter;
            return;
        }

        $this->must[] = $filter;
    }

    /**
     * Add a raw SQL fragment directly into the WHERE clause (SQL mode only).
     * The condition is ignored in Search API mode.
     *
     * Must be `protected` — same reason as parseWhereArgs.
     */
    protected function addRawCondition(string $sql, string $boolean): void
    {
        $this->whereSequence[] = [
            'boolean'   => $boolean,
            'negated'   => false,
            'condition' => null,
            'raw'       => $sql,
        ];
    }

    /**
     * Create a Manticoresearch\Query filter from field + operator + value.
     */
    protected function makeFilter(string $field, string $operator, mixed $value): \Manticoresearch\Query
    {
        return match (strtolower($operator)) {
            '=', '==' => new Equals($field, $value),
            '>'       => new Range($field, ['gt'  => $value]),
            '>='      => new Range($field, ['gte' => $value]),
            '<'       => new Range($field, ['lt'  => $value]),
            '<='      => new Range($field, ['lte' => $value]),
            default   => throw new \InvalidArgumentException("Unsupported operator [{$operator}]"),
        };
    }
}
