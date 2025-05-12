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

    public static function toSqlWhereClause(array $must, array $should, array $mustNot, ?string $match = null): string
    {
        $clauses = [];

        if ($match) {
            $clauses[] = "MATCH('@* " . addslashes($match) . "')";
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
            $notParts = array_map(fn($cond) => 'NOT (' . self::compileConditionSafe($cond) . ')', $mustNot);
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

    protected static function compileCondition($condition): string
    {
        if (isset($condition['match'])) {
            $value = addslashes($condition['match']['*'] ?? reset($condition['match']));
            return "MATCH('@* {$value}')";
        }

        if (isset($condition['equals'])) {
            foreach ($condition['equals'] as $field => $value) {
                $val = is_numeric($value) ? $value : "'" . addslashes($value) . "'";
                return "`{$field}` = {$val}";
            }
        }

        if (isset($condition['in'])) {
            foreach ($condition['in'] as $field => $values) {
                $quoted = array_map(function ($v) {
                    return is_numeric($v) ? $v : "'" . addslashes($v) . "'";
                }, $values);
                return "`{$field}` IN (" . implode(', ', $quoted) . ")";
            }
        }

        if (isset($condition['range'])) {
            foreach ($condition['range'] as $field => $ranges) {
                $rangeParts = [];
                foreach ($ranges as $op => $val) {
                    $symbol = match ($op) {
                        'gte' => '>=',
                        'lte' => '<=',
                        'gt'  => '>',
                        'lt'  => '<',
                        default => '='
                    };
                    $compiledVal = is_numeric($val) ? $val : "'" . addslashes($val) . "'";
                    $rangeParts[] = "`{$field}` {$symbol} {$compiledVal}";
                }
                return implode(' AND ', $rangeParts);
            }
        }

        return '1 = 1';
    }
}
