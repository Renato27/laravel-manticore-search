<?php

namespace ManticoreLaravel\Builder\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Handles extraction and hydration of Manticore result sets into Eloquent models.
 *
 * Properties referenced here are declared in ManticoreBuilderAbstract.
 */
trait HasResultHydration
{
    /**
     * Cached model attribute candidates (key name + fillable).
     *
     * @var array<int, string>|null
     */
    private ?array $modelAttributeCandidates = null;

    /**
     * Cached field maps keyed by normalized source-key signature.
     *
     * @var array<string, array<string, string>>
     */
    private array $fieldMapCache = [];

    protected function extractRawRows(mixed $results): array
    {
        if (is_array($results)) {
            return $this->extractRawRowsFromArrayResult($results);
        }

        return $this->extractRawRowsFromDefaultResult($results);
    }

    protected function extractRawRowsFromDefaultResult(mixed $results): array
    {
        $hits = iterator_to_array($results);

        return array_map(function ($hit) {
            $raw = $hit->getData() ?? [];
            $id  = $this->getID($hit, is_array($raw) ? $raw : []);

            if (filled($id)) {
                $raw = ['id' => $id] + $raw;
            }

            $data = $this->normalizeForModel($raw);

            try {
                $highlight = $hit->getHighlight();
                if (!empty($highlight)) {
                    $data['_highlight'] = $highlight;
                }
            } catch (\Throwable) {
            }

            return $data;
        }, $hits);
    }

    protected function extractRawRowsFromArrayResult(array $results): array
    {
        if (isset($results['hits']['hits'])) {
            $hits = iterator_to_array($results['hits']['hits']);

            return array_map(function ($hit) {
                $id  = $this->getID($hit);
                $raw = $hit['_source'] ?? [];

                if (filled($id)) {
                    $raw = ['id' => $id] + $raw;
                }

                return $this->normalizeForModel($raw);
            }, $hits);
        }

        if (!array_is_list($results)) {
            return [];
        }

        return array_map(function ($row) {
            if (is_array($row) && isset($row['_source']) && is_array($row['_source'])) {
                $id  = $this->getID($row);
                $raw = $row['_source'];

                if (filled($id)) {
                    $raw = ['id' => $id] + $raw;
                }

                return $this->normalizeForModel($raw);
            }

            if (is_array($row)) {
                $id = $this->getID($row, $row);

                if (filled($id) && !array_key_exists('id', $row)) {
                    $row = ['id' => $id] + $row;
                }

                return $this->normalizeForModel($row);
            }

            return $this->normalizeForModel(['value' => $row]);
        }, $results);
    }

    protected function hydrateModelsFromRows(array $rows): Collection
    {
        $models = array_map(
            fn (array $row) => $this->hydrateModelFromRow($row),
            $rows
        );

        return new Collection($models);
    }

    protected function hydrateModelFromRow(array $row): mixed
    {
        $model     = clone $this->model;
        $highlight = $row['_highlight'] ?? null;
        unset($row['_highlight']);

        $pk = $model->getKeyName();

        if (!empty($row[$pk])) {
            $model->setAttribute($pk, $row[$pk]);
        }

        $model->forceFill($row);

        if (!empty($highlight)) {
            $model->highlight = $highlight;
        }

        $model->exists = true;

        return $model;
    }

    private function getID(mixed $hit, array $raw = []): mixed
    {
        foreach (['_id', 'id'] as $key) {
            if (array_key_exists($key, $raw)) {
                return $raw[$key];
            }
        }

        if (is_array($hit)) {
            foreach (['_id', 'id'] as $key) {
                if (array_key_exists($key, $hit)) {
                    return $hit[$key];
                }
            }
            return null;
        }

        if (method_exists($hit, 'getData')) {
            try {
                $data = $hit->getData();
                if (is_array($data)) {
                    foreach (['_id', 'id'] as $key) {
                        if (array_key_exists($key, $data)) {
                            return $data[$key];
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (property_exists($hit, 'id')) {
            return $hit->id;
        }

        if (method_exists($hit, 'getId')) {
            try {
                return $hit->getId();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function normalizeForModel(array $source): array
    {
        $model    = $this->model;
        $fieldMap = $this->buildFieldMap(array_keys($source));

        $out = [];
        foreach ($source as $k => $v) {
            $out[$fieldMap[$k] ?? $k] = $v;
        }

        $pk = $model->getKeyName();
        if (!empty($out['id']) && $pk !== 'id' && empty($out[$pk])) {
            $out[$pk] = $out['id'];
            unset($out['id']);
        }

        $casts = method_exists($model, 'getCasts') ? $model->getCasts() : [];

        foreach ($casts as $attr => $cast) {
            if (!array_key_exists($attr, $out)) {
                continue;
            }

            $castLower = strtolower($cast);
            $val       = $out[$attr];

            if (str_contains($castLower, 'datetime') || str_contains($castLower, 'date')) {
                if (is_numeric($val) || (is_string($val) && ctype_digit($val))) {
                    $out[$attr] = Carbon::createFromTimestampUTC((int) $val);
                }
            } elseif ($castLower === 'boolean' || $castLower === 'bool') {
                $out[$attr] = match (true) {
                    $val === '0' || $val === 0 => false,
                    $val === '1' || $val === 1 => true,
                    default                    => (bool) $val,
                };
            } elseif (in_array($castLower, ['array', 'json', 'collection'], true)) {
                if (is_string($val)) {
                    $decoded = json_decode($val, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $out[$attr] = $decoded;
                    }
                }
            }
        }

        return $out;
    }

    private function buildFieldMap(array $sourceKeys): array
    {
        $cacheKey = $this->fieldMapCacheKey($sourceKeys);

        if (isset($this->fieldMapCache[$cacheKey])) {
            return $this->fieldMapCache[$cacheKey];
        }

        $declaredMap = [];
        $model       = $this->model;

        if (property_exists($model, 'manticoreAttributeMap') && is_array($model->manticoreAttributeMap)) {
            $declaredMap = $model->manticoreAttributeMap;
        } elseif (method_exists($model, 'manticoreAttributeMap')) {
            $declaredMap = (array) $model->manticoreAttributeMap();
        }

        $explicit = [];
        foreach ($declaredMap as $from => $to) {
            $explicit[strtolower($from)] = $to;
        }

        $sourceKeyIndex = [];
        foreach ($sourceKeys as $k) {
            $sourceKeyIndex[strtolower($k)] = $k;
        }

        $candidates = $this->modelAttributeCandidates();

        $variants = static function (string $name): array {
            $o = $name;
            $l = strtolower($name);
            $s = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
            $n = preg_replace('/[^a-z0-9]/', '', strtolower($name));

            return array_unique([$o, $l, $s, $n]);
        };

        $map = [];

        foreach ($explicit as $fromLower => $to) {
            if (isset($sourceKeyIndex[$fromLower])) {
                $map[$sourceKeyIndex[$fromLower]] = $to;
            }
        }

        foreach ($candidates as $col) {
            foreach ($variants($col) as $v) {
                $vLower = strtolower($v);
                if (isset($sourceKeyIndex[$vLower]) && !in_array($sourceKeyIndex[$vLower], array_keys($map), true)) {
                    $map[$sourceKeyIndex[$vLower]] = $col;
                    break;
                }
            }
        }

        if (isset($sourceKeyIndex['id'])) {
            $map[$sourceKeyIndex['id']] = $model->getKeyName();
        }

        return $this->fieldMapCache[$cacheKey] = $map;
    }

    private function fieldMapCacheKey(array $sourceKeys): string
    {
        $normalized = array_map('strtolower', $sourceKeys);
        sort($normalized);

        return implode('|', $normalized);
    }

    /**
     * @return array<int, string>
     */
    private function modelAttributeCandidates(): array
    {
        if ($this->modelAttributeCandidates !== null) {
            return $this->modelAttributeCandidates;
        }

        return $this->modelAttributeCandidates = array_values(array_unique(array_merge(
            [$this->model->getKeyName()],
            $this->model->getFillable()
        )));
    }
}
