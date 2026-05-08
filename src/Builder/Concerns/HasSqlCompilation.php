<?php

namespace ManticoreLaravel\Builder\Concerns;

use ManticoreLaravel\Builder\Grammar\ManticoreGrammar;

trait HasSqlCompilation
{
    protected function buildSelectClause(): string
    {
        return !empty($this->select) ? implode(', ', $this->select) : '*';
    }

    protected function buildWhereClause(): string
    {
        $clause = ManticoreGrammar::toSqlWhereClauseFromSequence(
            $this->whereSequence,
            $this->match
        );

        return $clause ? "WHERE {$clause}" : '';
    }

    protected function buildGroupByClause(): string
    {
        return !empty($this->groupBy)
            ? 'GROUP BY ' . implode(', ', $this->groupBy)
            : '';
    }

    protected function buildOrderByClause(): string
    {
        if (empty($this->sort)) {
            return '';
        }

        $orders = [];

        foreach ($this->sort as $s) {
            foreach ($s as $field => $dir) {
                $orders[] = ManticoreGrammar::compileFieldReference($field) . ' ' . strtoupper($dir);
            }
        }

        return 'ORDER BY ' . implode(', ', $orders);
    }

    protected function buildHavingClause(): string
    {
        return !empty($this->having)
            ? 'HAVING ' . implode(' AND ', $this->having)
            : '';
    }

    protected function buildLimitClause(): string
    {
        $limit  = $this->limit;
        $offset = $this->offset;

        if ($limit !== null && $offset !== null) {
            return "LIMIT {$offset}, {$limit}";
        }

        if ($limit !== null) {
            return "LIMIT {$limit}";
        }

        return '';
    }

    protected function buildOptionClause(): string
    {
        $maxMatches = $this->option['max_matches']
            ?? $this->resolveConnectionConfig()['max_matches'];

        $clauses = ["max_matches={$maxMatches}"];

        foreach ($this->option as $key => $value) {
            if ($key === 'max_matches' || $value === null) {
                continue;
            }

            $clauses[] = "{$key}=" . $this->formatOptionValue($value);
        }

        return 'OPTION ' . implode(',', $clauses);
    }

    private function formatOptionValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);

            if ($isAssoc) {
                $pairs = [];
                foreach ($value as $k => $v) {
                    $pairs[] = "{$k}={$v}";
                }
                return '(' . implode(',', $pairs) . ')';
            }

            return '(' . implode(',', $value) . ')';
        }

        return (string) $value;
    }

    /**
     * Compile the full SELECT SQL query for SQL-mode execution.
     */
    protected function buildSqlQuery(): string
    {
        $select  = $this->buildSelectClause();
        $index   = $this->resolveIndexName();
        $where   = $this->buildWhereClause();
        $groupBy = $this->buildGroupByClause();
        $having  = $this->buildHavingClause();
        $orderBy = $this->buildOrderByClause();
        $limit   = $this->buildLimitClause();
        $option  = $this->buildOptionClause();

        return trim("SELECT {$select} FROM `{$index}` {$where} {$groupBy} {$having} {$orderBy} {$limit} {$option}");
    }
}
