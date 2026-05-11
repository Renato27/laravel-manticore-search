<?php

namespace ManticoreLaravel\Builder\Concerns;

trait HasConsolidation
{
    /**
     * Consolidate an array of raw rows by grouping on `$groupField`.
     * Rows that share the same group value are merged: fields with a single
     * unique value become top-level attributes, variable fields are collected
     * in the `$historyAttribute` array.
     *
     * @param  array<int, array>  $rows
     * @return array<int, array>
     */
    protected function consolidateRawRows(
        array $rows,
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): array {
        if (empty($rows)) {
            return [];
        }

        $grouped = [];

        foreach ($rows as $row) {
            $groupValue = $this->resolveRowFieldValue($row, $groupField);
            $grouped[(string) $groupValue][] = $row;
        }

        $consolidated = [];

        foreach ($grouped as $groupRows) {
            $allKeys = array_values(array_unique(
                array_merge(...array_map('array_keys', $groupRows))
            ));

            $common       = [];
            $variableKeys = [];

            foreach ($allKeys as $key) {
                $values = array_map(
                    fn (array $row) => array_key_exists($key, $row) ? $row[$key] : null,
                    $groupRows
                );

                $serialized = array_map('serialize', $values);

                if (count(array_unique($serialized)) === 1) {
                    $common[$key] = $values[0];
                } else {
                    $variableKeys[] = $key;
                }
            }

            $history = array_map(
                function (array $row) use ($variableKeys, $groupField, $preserveGroupFieldInHistory): array {
                    $snapshot = [];

                    foreach ($variableKeys as $key) {
                        if (!$preserveGroupFieldInHistory && $this->isSameFieldKey($key, $groupField)) {
                            continue;
                        }

                        if (array_key_exists($key, $row)) {
                            $snapshot[$key] = $row[$key];
                        }
                    }

                    return $snapshot;
                },
                $groupRows
            );

            $common[$historyAttribute] = array_values($history);
            $consolidated[]            = $common;
        }

        return $consolidated;
    }

    protected function normalizeFieldKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }

    protected function isSameFieldKey(string $left, string $right): bool
    {
        return $this->normalizeFieldKey($left) === $this->normalizeFieldKey($right);
    }

    protected function resolveRowFieldValue(array $row, string $field): mixed
    {
        if (array_key_exists($field, $row)) {
            return $row[$field];
        }

        $target = $this->normalizeFieldKey($field);

        foreach ($row as $key => $value) {
            if (is_string($key) && $this->normalizeFieldKey($key) === $target) {
                return $value;
            }
        }

        return null;
    }
}
