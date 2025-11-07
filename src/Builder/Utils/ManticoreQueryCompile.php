<?php

namespace ManticoreLaravel\Builder\Utils;

class ManticoreQueryCompile
{
    public static function toRawSql(array $queryBody): string
    {
        $index = $queryBody['index'] ?? 'index';
        $limit = $queryBody['limit'] ?? 20;
        $offset = $queryBody['offset'] ?? 0;

        $where = self::buildWhereClause($queryBody['query'] ?? []);

        $sql = "SELECT * FROM {$index}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        $sql .= " LIMIT {$offset}, {$limit}";

        return $sql;
    }

    public static function toSqlWhereClause(array $must, array $should, array $mustNot, ?array $match = null): string
    {
        $clauses = [];

        if ($match) {
            foreach($match as $m) {
                $clauses[] = "MATCH('@{$m['field']} " . addslashes($m['keywords']) . "')";
            }
        }

        if (!empty($must)) {
            $mustParts = array_map([self::class, 'compileConditionSafe'], $must);
            $clauses[] = '(' . implode(' AND ', $mustParts) . ')';
        }

        if (!empty($should)) {
            $shouldParts = array_map([self::class, 'compileConditionSafe'], $should);
            $clauses[] = '(' . implode(' OR ', $shouldParts) . ')';
        }

        if (!empty($mustNot)) {
            $notParts = array_map([self::class, 'compileConditionSafeNegated'], $mustNot);
            $clauses[] = implode(' AND ', $notParts);
        }

        return implode(' AND ', array_filter($clauses));
    }

    protected static function buildWhereClause(array $query): string
    {
        if (!isset($query['bool'])) {
            return '';
        }

        $bool = $query['bool'];
        return self::toSqlWhereClause(
            $bool['must'] ?? [],
            $bool['should'] ?? [],
            $bool['must_not'] ?? [],
            null
        );
    }

    protected static function compileConditionSafe($condition): string
    {
        if (is_object($condition) && method_exists($condition, 'toArray')) {
            $condition = $condition->toArray();
        }

        return self::compileCondition($condition);
    }

    protected static function compileConditionSafeNegated($condition): string
    {
        if (is_object($condition) && method_exists($condition, 'toArray')) {
            $condition = $condition->toArray();
        }

        return self::compileCondition($condition, true);
    }

    protected static function compileCondition($condition, bool $negated = false): string
    {
        if (isset($condition['match'])) {
            $value = addslashes($condition['match']['*'] ?? reset($condition['match']));
            if ($negated) {
                // For negated MATCH, we need to use a different approach
                return "NOT MATCH('@* {$value}')";
            }
            return "MATCH('@* {$value}')";
        }

        if (isset($condition['equals'])) {
            foreach ($condition['equals'] as $field => $value) {
                $val = is_numeric($value) ? $value : "'" . addslashes($value) . "'";
                $operator = $negated ? '<>' : '=';
                return "`{$field}` {$operator} {$val}";
            }
        }

        if (isset($condition['in'])) {
            foreach ($condition['in'] as $field => $values) {
                $quoted = array_map(function ($v) {
                    return is_numeric($v) ? $v : "'" . addslashes($v) . "'";
                }, $values);
                $operator = $negated ? 'NOT IN' : 'IN';
                return "`{$field}` {$operator} (" . implode(', ', $quoted) . ")";
            }
        }

        if (isset($condition['range'])) {
            foreach ($condition['range'] as $field => $ranges) {
                $rangeParts = [];
                foreach ($ranges as $op => $val) {
                    $symbol = match ($op) {
                        'gte' => $negated ? '<' : '>=',
                        'lte' => $negated ? '>' : '<=',
                        'gt'  => $negated ? '<=' : '>',
                        'lt'  => $negated ? '>=' : '<',
                        default => $negated ? '<>' : '='
                    };
                    $compiledVal = is_numeric($val) ? $val : "'" . addslashes($val) . "'";
                    $rangeParts[] = "`{$field}` {$symbol} {$compiledVal}";
                }
                if ($negated && count($rangeParts) > 1) {
                    // For negated ranges with multiple conditions, use OR instead of AND
                    return implode(' OR ', $rangeParts);
                }
                return implode(' AND ', $rangeParts);
            }
        }

        return '1 = 1';
    }
}
