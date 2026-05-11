<?php

namespace ManticoreLaravel\Builder\Grammar;

final class ManticoreGrammar
{
    /**
     * Compile a WHERE clause from an ordered sequence of conditions.
     * This is the only method used by the builder for SQL query mode.
     *
     * @param  array<int, array{boolean: string, negated: bool, condition: mixed, raw?: string}>  $sequence
     * @param  array<int, array{field: string, keywords: string, boolean: string}>|null  $match
     */
    public static function toSqlWhereClauseFromSequence(array $sequence, ?array $match = null): string
    {
        $clauses = self::compileMatchClauses($match);

        foreach ($sequence as $index => $item) {
            $boolean   = strtoupper($item['boolean'] ?? 'AND');
            $negated   = (bool) ($item['negated'] ?? false);
            $condition = $item['condition'] ?? null;
            $raw       = $item['raw'] ?? null;

            if ($raw !== null) {
                $clauses[] = empty($clauses) && $index === 0
                    ? "({$raw})"
                    : "{$boolean} ({$raw})";
                continue;
            }

            $compiled = $negated
                ? self::compileConditionSafeNegated($condition)
                : self::compileConditionSafe($condition);

            if (!$compiled) {
                continue;
            }

            $clauses[] = empty($clauses) && $index === 0
                ? "({$compiled})"
                : "{$boolean} ({$compiled})";
        }

        return implode(' ', $clauses);
    }

    /**
     * Compile a field reference, backtick-quoting simple identifiers.
     */
    public static function compileFieldReference(string $field): string
    {
        $field = trim($field);

        if (preg_match('/[()\s,.]/', $field)) {
            return $field;
        }

        return "`{$field}`";
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build the MATCH('…') clause parts from a match array, returning a list of
     * compiled SQL fragments ready to prepend to the WHERE clause.
     *
     * @param  array<int, array{field: string, keywords: string, boolean: string}>|null  $match
     * @return list<string>
     */
    private static function compileMatchClauses(?array $match): array
    {
        if (empty($match)) {
            return [];
        }

        $matchParts = [];

        foreach ($match as $m) {
            if (!empty($m['field']) && str_contains($m['field'], '@')) {
                $field = $m['field'];
            } elseif (!empty($m['field'])) {
                $field = "@{$m['field']}";
            } else {
                $field = '@*';
            }

            $keywords = addslashes($m['keywords']);
            $boolean  = strtoupper($m['boolean'] ?? 'AND');

            if (empty($matchParts)) {
                $matchParts[] = "({$field} ({$keywords}))";
            } else {
                $op           = ($boolean === 'OR') ? '| ' : '';
                $matchParts[] = "{$op}({$field} ({$keywords}))";
            }
        }

        if (empty($matchParts)) {
            return [];
        }

        return ["MATCH('" . implode(' ', $matchParts) . "')"];
    }

    private static function compileConditionSafe(mixed $condition): string
    {
        if (is_object($condition) && method_exists($condition, 'toArray')) {
            $condition = $condition->toArray();
        }

        return self::compileCondition($condition);
    }

    private static function compileConditionSafeNegated(mixed $condition): string
    {
        if (is_object($condition) && method_exists($condition, 'toArray')) {
            $condition = $condition->toArray();
        }

        return self::compileCondition($condition, true);
    }

    private static function compileCondition(mixed $condition, bool $negated = false): string
    {
        if (is_string($condition)) {
            return $negated ? "NOT ({$condition})" : $condition;
        }

        if (isset($condition['match'])) {
            $value = addslashes($condition['match']['*'] ?? reset($condition['match']));

            return $negated
                ? "NOT MATCH('(@* {$value})')"
                : "MATCH('(@* {$value})')";
        }

        if (isset($condition['equals'])) {
            foreach ($condition['equals'] as $field => $value) {
                $val           = self::compileScalarValue($value);
                $operator      = $negated ? '<>' : '=';
                $compiledField = self::compileFieldReference($field);

                return "{$compiledField} {$operator} {$val}";
            }
        }

        if (isset($condition['in'])) {
            foreach ($condition['in'] as $field => $values) {
                $quoted        = array_map([self::class, 'compileScalarValue'], $values);
                $operator      = $negated ? 'NOT IN' : 'IN';
                $compiledField = self::compileFieldReference($field);

                return "{$compiledField} {$operator} (" . implode(', ', $quoted) . ")";
            }
        }

        if (isset($condition['range'])) {
            foreach ($condition['range'] as $field => $ranges) {
                $rangeParts = [];

                foreach ($ranges as $op => $val) {
                    $symbol = match ($op) {
                        'gte'   => $negated ? '<'  : '>=',
                        'lte'   => $negated ? '>'  : '<=',
                        'gt'    => $negated ? '<=' : '>',
                        'lt'    => $negated ? '>=' : '<',
                        default => $negated ? '<>' : '=',
                    };

                    $compiledVal   = self::compileScalarValue($val);
                    $compiledField = self::compileFieldReference($field);
                    $rangeParts[]  = "{$compiledField} {$symbol} {$compiledVal}";
                }

                return $negated && count($rangeParts) > 1
                    ? implode(' OR ', $rangeParts)
                    : implode(' AND ', $rangeParts);
            }
        }

        return '1 = 1';
    }

    private static function compileScalarValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }
}
