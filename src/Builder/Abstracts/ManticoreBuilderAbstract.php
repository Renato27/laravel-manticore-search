<?php

namespace ManticoreLaravel\Builder\Abstracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use ManticoreLaravel\Builder\Utils\ManticoreQueryCompile;
use ManticoreLaravel\Builder\Utils\Utf8SafeSearch;
use ManticoreLaravel\Support\ManticoreManager;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Manticoresearch\Table;

abstract class ManticoreBuilderAbstract
{
    protected $model;
    protected array $option = [];
    protected array $match = [];
    protected array $must = [];
    protected array $should = [];
    protected array $mustNot = [];
    protected array $sort = [];
    protected array $aggregations = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected bool $highlight = false;
    protected ?string $rawQuery = null;
    protected bool $rawQueryMode = false;
    protected array $groupBy = [];
    protected array $select = [];
    protected array $having = [];
    protected ?int $maxMatches = null;
    protected array $eagerQueue = [];
    protected array $scriptFields = [];
    protected array $whereSequence = [];
    protected array|string|null $indexOverride = null;
    protected ?string $connectionName = null;

    /**
     * Lazily resolved and cached client for the lifetime of this builder instance.
     * Avoids opening multiple connections during a single query chain.
     */
    private ?Client $client = null;

    /**
     * Cached resolved connection config for the lifetime of this builder instance.
     * Avoids repeated container/config repository resolution.
     */
    private ?array $resolvedConnectionConfig = null;

    /**
     * Cached resolved index name for the lifetime of this builder instance.
     */
    private ?string $resolvedIndexName = null;

    /**
     * Cached candidate model attributes used during field mapping.
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

    public function __construct($model)
    {
        $this->model = $model;
    }

    /**
     * Resolve the active connection configuration through the centralized resolver.
     * Supports legacy flat config, default named connection, and explicit named connections.
     */
    protected function resolveConnectionConfig(): array
    {
        if ($this->resolvedConnectionConfig === null) {
            $this->resolvedConnectionConfig = app(ManticoreManager::class)
                ->resolveConfig($this->connectionName);
        }

        return $this->resolvedConnectionConfig;
    }

    protected function resolveIndexName(): string
    {
        if ($this->resolvedIndexName !== null) {
            return $this->resolvedIndexName;
        }

        if ($this->indexOverride !== null) {
            return $this->resolvedIndexName = is_array($this->indexOverride)
                ? implode(',', $this->indexOverride)
                : $this->indexOverride;
        }

        if (method_exists($this->model, 'searchableAs')) {
            $indexes = $this->model->searchableAs();

            if (is_array($indexes)) {
                return $this->resolvedIndexName = implode(',', $indexes);
            }

            return $this->resolvedIndexName = $indexes;
        }

        return $this->resolvedIndexName = $this->model->getTable();
    }

    protected function applyIndex(Search $search): void
    {
        $indexName = $this->resolveIndexName();

        $search->setTable($indexName);

        $ref = new \ReflectionClass($search);
        $prop = $ref->getProperty('params');
        $prop->setAccessible(true);
        $params = $prop->getValue($search);
        $params['index'] = $params['table'] ?? $indexName;
        $prop->setValue($search, $params);
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = app(ManticoreManager::class)->client($this->connectionName);
        }

        return $this->client;
    }

    protected function flushResolvedConnectionState(): void
    {
        $this->client = null;
        $this->resolvedConnectionConfig = null;
    }

    protected function flushResolvedIndexState(): void
    {
        $this->resolvedIndexName = null;
    }

    protected function getTable(): Table
    {
        $client = $this->getClient();
        $table = new Table($client);
        $table->setName($this->resolveIndexName());
        return $table;
    }

    protected function executeSqlQuery(string $sql, ?bool $rawMode = null): mixed
    {
        $client = $this->getClient();

        return $client->sql($sql, $rawMode ?? $this->rawQueryMode);
    }

     protected function fetchRawQuery(): Collection
    {
        $results = $this->executeSqlQuery($this->rawQuery, $this->rawQueryMode);

        $rows = $this->extractRawRows($results);
        $models = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    protected function resolveResults($results): Collection
    {
        $rows = $this->extractRawRows($results);

        return $this->hydrateModelsFromRows($rows);
    }

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
            $id = $this->getID($hit, is_array($raw) ? $raw : []);

            if (filled($id)) {
                $raw = ['id' => $id] + $raw;
            }

            $data = $this->normalizeForModel($raw);

            try {
                $highlight = $hit->getHighlight();
                if (!empty($highlight)) {
                    $data['_highlight'] = $highlight;
                }
            } catch (\Throwable $e) {
            }

            return $data;
        }, $hits);
    }

    protected function extractRawRowsFromArrayResult(array $results): array
    {
        if (isset($results['hits']['hits'])) {
            $hits = iterator_to_array($results['hits']['hits']);

            return array_map(function ($hit) {
                $id = $this->getID($hit);
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
                $id = $this->getID($row);
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
        $models = array_map(function (array $row) {
            return $this->hydrateModelFromRow($row);
        }, $rows);

        return new Collection($models);
    }

    protected function hydrateModelFromRow(array $row): mixed
    {
        $model = clone $this->model;
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
        $groupingDebug = [];

        foreach ($rows as $idx => $row) {
            $groupValue = $this->resolveRowFieldValue($row, $groupField);
            $grouped[(string) $groupValue][] = $row;
            
            if ($idx < 5) { // Log first 5 rows
                $groupingDebug[] = [
                    'row_index' => $idx,
                    'groupValue' => $groupValue,
                    'groupValue_type' => gettype($groupValue),
                    'row_keys' => array_keys($row),
                ];
            }
        }

        \Illuminate\Support\Facades\Log::debug('consolidateRawRows grouping', [
            'input_rows_count' => count($rows),
            'groupField' => $groupField,
            'unique_group_values' => count($grouped),
            'sample_groupValues' => array_slice(array_keys($grouped), 0, 5),
            'first_5_rows_grouping' => $groupingDebug,
        ]);

        $consolidated = [];

        foreach ($grouped as $groupRows) {
            $allKeys = [];
            foreach ($groupRows as $row) {
                $allKeys = array_merge($allKeys, array_keys($row));
            }
            $allKeys = array_values(array_unique($allKeys));

            $common = [];
            $variableKeys = [];

            foreach ($allKeys as $key) {
                $values = array_map(
                    fn (array $row) => array_key_exists($key, $row) ? $row[$key] : null,
                    $groupRows
                );

                $serialized = array_map(
                    fn ($value) => serialize($value),
                    $values
                );

                if (count(array_unique($serialized)) === 1) {
                    $common[$key] = $values[0];
                } else {
                    $variableKeys[] = $key;
                }
            }

            $history = array_map(function (array $row) use ($variableKeys, $groupField, $preserveGroupFieldInHistory) {
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
            }, $groupRows);

            $common[$historyAttribute] = array_values($history);
            $consolidated[] = $common;
        }

        \Illuminate\Support\Facades\Log::debug('consolidateRawRows result', [
            'input_rows_count' => count($rows),
            'output_consolidated_count' => count($consolidated),
        ]);

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
            \Illuminate\Support\Facades\Log::debug('resolveRowFieldValue direct match', [
                'field' => $field,
                'value' => $row[$field],
            ]);
            return $row[$field];
        }

        $target = $this->normalizeFieldKey($field);
        $normalized_row = [];

        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = $this->normalizeFieldKey($key);
            $normalized_row[$normalizedKey] = [
                'original_key' => $key,
                'value' => $value,
                'normalized_key' => $normalizedKey,
            ];

            if ($normalizedKey === $target) {
                \Illuminate\Support\Facades\Log::debug('resolveRowFieldValue case-insensitive match', [
                    'field' => $field,
                    'normalized_field' => $target,
                    'found_key' => $key,
                    'value' => $value,
                ]);
                return $value;
            }
        }

        // Log when field is not found
        \Illuminate\Support\Facades\Log::warning('resolveRowFieldValue NOT found', [
            'field' => $field,
            'normalized_field' => $target,
            'row_keys' => array_keys($row),
            'normalized_keys' => array_keys($normalized_row),
        ]);

        return null;
    }

    private function buildFieldMap(array $sourceKeys): array
    {
        $cacheKey = $this->fieldMapCacheKey($sourceKeys);

        if (isset($this->fieldMapCache[$cacheKey])) {
            return $this->fieldMapCache[$cacheKey];
        }

        $declaredMap = [];
        $model = $this->model;
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
    
        $variants = function (string $name): array {
            $o = $name;
            $l = strtolower($name);
            $s = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
            $n = preg_replace('/[^a-z0-9]/', '', strtolower($name));

            return array_unique([$o, $l, $s, $n]);
        };

        $map = [];

        foreach ($explicit as $fromLower => $to) {
            if (isset($sourceKeyIndex[$fromLower])) {
                $fromOriginal = $sourceKeyIndex[$fromLower];
                $map[$fromOriginal] = $to;
            }
        }

        foreach ($candidates as $col) {
            foreach ($variants($col) as $v) {
                $vLower = strtolower($v);
                if (isset($sourceKeyIndex[$vLower]) && !in_array($sourceKeyIndex[$vLower], array_keys($map), true)) {
                    $fromOriginal = $sourceKeyIndex[$vLower];
                    $map[$fromOriginal] = $col;
                    break;
                }
            }
        }

        if (isset($sourceKeyIndex['id'])) {
            $pk = $model->getKeyName();
            $map[$sourceKeyIndex['id']] = $pk;
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

    private function normalizeForModel(array $source): array
    {
        $model = $this->model;
        $fieldMap = $this->buildFieldMap(array_keys($source));

        $out = [];

        foreach ($source as $k => $v) {
            $target = $fieldMap[$k] ?? $k;
            $out[$target] = $v;
        }

        $pk = $model->getKeyName();
        if (!empty($out['id']) && $pk !== 'id' && empty($out[$pk])) {
            $out[$pk] = $out['id'];
            unset($out['id']);
        }

        $casts = method_exists($model, 'getCasts') ? $model->getCasts() : [];
        foreach ($casts as $attr => $cast) {
            $cast = strtolower($cast);
            if (!array_key_exists($attr, $out)) continue;

            $val = $out[$attr];
            if (str_contains($cast, 'datetime')) {
                if (is_numeric($val) || (is_string($val) && ctype_digit($val))) {
                    $out[$attr] = Carbon::createFromTimestampUTC((int)$val);
                }
            } elseif ($cast === 'boolean') {
                if ($val === '0' || $val === 0) $out[$attr] = false;
                if ($val === '1' || $val === 1) $out[$attr] = true;
            }
        }

        return $out;
    }

    private function getID(mixed $hit, array $raw = []): mixed
    {
        if (array_key_exists('_id', $raw)) {
            return $raw['_id'];
        }

        if (array_key_exists('id', $raw)) {
            return $raw['id'];
        }

        if (is_array($hit) && array_key_exists('_id', $hit)) {
            return $hit['_id'];
        }

        if (is_array($hit) && array_key_exists('id', $hit)) {
            return $hit['id'];
        }

        if (!is_array($hit) && method_exists($hit, 'getData')) {
            try {
                $data = $hit->getData();

                if (is_array($data)) {
                    if (array_key_exists('_id', $data)) {
                        return $data['_id'];
                    }

                    if (array_key_exists('id', $data)) {
                        return $data['id'];
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!is_array($hit) && property_exists($hit, 'id')) {
            return $hit->id;
        }

        if (!is_array($hit) && method_exists($hit, 'getId')) {
            try {
                return $hit->getId();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

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

    protected function search(): Search
    {
        $client = $this->getClient();

        $search = new Utf8SafeSearch($client);
        $this->applyIndex($search);

        $bool = new \Manticoresearch\Query\BoolQuery();

        if ($this->match) {
            foreach($this->match as $match)
            {
                $match = new \Manticoresearch\Query\MatchQuery($match['keywords'], $match['field']);
                $bool->must($match);
            }
        }

        foreach ($this->must as $filter) {
            $bool->must($filter);
        }

        foreach ($this->should as $filter) {
            $bool->should($filter);
        }

        foreach ($this->mustNot as $filter) {
            $bool->mustNot($filter);
        }

        $search->search($bool);

        if ($this->limit) {
            $search->limit($this->limit);
        }

        if ($this->offset) {
            $search->offset($this->offset);
        }

        if ($this->sort) {
            foreach ($this->sort as $s) {
                foreach ($s as $field => $dir) {
                    $search->sort($field, $dir);
                }
            }
        }

        if ($this->highlight) {
            $search->highlight(['*' => new \stdClass()]);
        }

        if (!empty($this->aggregations)) {
            foreach ($this->aggregations as $name => $agg) {
                $search->facet($agg['terms']['field'], $name);
            }
        }

        if (!empty($this->option)) {
            foreach ($this->option as $key => $value) {
                $search->option($key, $value);
            }
        }

        return $search;
    }

    protected function makeFilter(string $field, string $operator, mixed $value): \Manticoresearch\Query
    {
        return match (strtolower($operator)) {
            '=', '=='   => new \Manticoresearch\Query\Equals($field, $value),
            '>'         => new \Manticoresearch\Query\Range($field, ['gt' => $value]),
            '>='        => new \Manticoresearch\Query\Range($field, ['gte' => $value]),
            '<'         => new \Manticoresearch\Query\Range($field, ['lt' => $value]),
            '<='        => new \Manticoresearch\Query\Range($field, ['lte' => $value]),
            default     => throw new \InvalidArgumentException("Unsupported operator [$operator]"),
        };
    }

    protected function buildSelectClause(): string
    {
        return !empty($this->select) ? implode(', ', $this->select) : '*';
    }

    protected function buildWhereClause(): string
    {
        $clause = ManticoreQueryCompile::toSqlWhereClauseFromSequence(
            $this->whereSequence,
            $this->match
        );
        return $clause ? "WHERE {$clause}" : '';
    }

    protected function buildGroupByClause(): string
    {
        return !empty($this->groupBy) ? 'GROUP BY ' . implode(', ', $this->groupBy) : '';
    }

    protected function buildOrderByClause(): string
    {
        if (empty($this->sort)) {
            return '';
        }

        $orders = [];
        foreach ($this->sort as $s) {
            foreach ($s as $field => $dir) {
                $orders[] = "`{$field}` " . strtoupper($dir);
            }
        }

        return 'ORDER BY ' . implode(', ', $orders);
    }

    protected function buildHavingClause(): string
    {
        return !empty($this->having) ? 'HAVING ' . implode(' AND ', $this->having) : '';
    }

    private function buildOptionClause(): string
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

    protected function buildLimitClause(): string
    {
        $limit  = isset($this->limit)  ? $this->limit : null;
        $offset = isset($this->offset) ? $this->offset : null;

        if ($limit !== null && $offset !== null) {
            return "LIMIT {$offset}, {$limit}";
        }

        if ($limit !== null) {
            return "LIMIT {$limit}";
        }

        return '';
    }

    protected function buildSqlQuery(): string
    {
        $select   = $this->buildSelectClause();
        $where    = $this->buildWhereClause();
        $groupBy  = $this->buildGroupByClause();
        $orderBy  = $this->buildOrderByClause();
        $having   = $this->buildHavingClause();
        $limit    = $this->buildLimitClause();
        $option   = $this->buildOptionClause();
        return trim("SELECT {$select} FROM {$this->resolveIndexName()} {$where} {$groupBy} {$having} {$orderBy} {$limit} {$option}");
    }
}
