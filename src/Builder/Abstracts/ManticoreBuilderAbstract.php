<?php

namespace ManticoreLaravel\Builder\Abstracts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use ManticoreLaravel\Builder\Utils\ManticoreQueryCompile;
use ManticoreLaravel\Builder\Utils\Utf8SafeClient;
use ManticoreLaravel\Builder\Utils\Utf8SafeSearch;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Manticoresearch\Table;

abstract class ManticoreBuilderAbstract
{
    protected $model;
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

    public function __construct($model)
    {
        $this->model = $model;
    }

    protected function resolveIndexName(): string
    {
        if (method_exists($this->model, 'searchableAs')) {
            $indexes = $this->model->searchableAs();
            if (is_array($indexes)) {
                return implode(',', $indexes);
            }
            return $indexes;
        }

        return $this->model->getTable();
    }

    protected function applyIndex(Search $search)
    {
        $search->setTable($this->resolveIndexName());

        $ref = new \ReflectionClass($search);
        $prop = $ref->getProperty('params');
        $prop->setAccessible(true);
        $params = $prop->getValue($search);
        $params['index'] = $params['table'] ?? $this->resolveIndexName();
        $prop->setValue($search, $params);
    }

    protected function getClient(): Client
    {
        return new Utf8SafeClient([
            'host' => config('manticore.host'),
            'port' => config('manticore.port'),
            'username' => config('manticore.username'),
            'password' => config('manticore.password'),
            'transport' => config('manticore.transport'),
            'timeout' => config('manticore.timeout'),
            'persistent' => config('manticore.persistent'),
        ]);
    }

    protected function getTable(): Table
    {
        $client = $this->getClient();
        $table = new Table($client);
        $table->setName($this->resolveIndexName());
        return $table;
    }

    protected function fetchRawQuery(): Collection
    {
        $client = $this->getClient();

        $results = $client->sql($this->rawQuery, $this->rawQueryMode);
        $col = $this->resolveResults($results);
        return $this->applyEloquentWith($col);
    }

    protected function resolveResults($results): Collection
    {
        if (is_array($results)) {
            return $this->resolveResultsArray($results);
        } else {
            return $this->resolveResultsDefault($results);
        }
    }

    private function resolveResultsDefault($results): Collection
    {
        $hits = iterator_to_array($results);
        $models = array_map(function ($hit) {
            $model = clone $this->model;
            $id = $this->getID($hit);
            $raw = $hit->getData() ?? [];
            if (filled($id)) $raw = ['id' => $id] + $raw;

            $data = $this->normalizeForModel($raw);
            $pk = $model->getKeyName();
            if (!empty($data[$pk])) $model->setAttribute($pk, $data[$pk]);
            $model->forceFill($data);
            try { $highlight = $hit->getHighlight(); if (!empty($highlight)) $model->highlight = $highlight; } catch (\Throwable $e) {}
            $model->exists = true;
            return $model;
        }, $hits);

        return new Collection($models);
    }

    private function resolveResultsArray($results): Collection
    {
        $hits = $results['hits']['hits'] ?? [];
        $hits = iterator_to_array($hits);

        $models = array_map(function ($hit) {
            $model = clone $this->model;
            $id = $this->getID($hit);
            $raw = $hit['_source'] ?? [];
            if (filled($id)) $raw = ['id' => $id] + $raw;

            $data = $this->normalizeForModel($raw);
            $pk = $model->getKeyName();
            if (!empty($data[$pk])) $model->setAttribute($pk, $data[$pk]);
            $model->forceFill($data);
            $model->exists = true;
            return $model;
        }, $hits);

        return new Collection($models);
    }

    private function buildFieldMap(array $sourceKeys): array
    {
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

        $candidates = array_unique(array_merge([$model->getKeyName()], $model->getFillable()));

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

        return $map;
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

    private function getID($hit)
    {
        if (is_array($hit) && array_key_exists('_id', $hit)) {
            return $hit['_id'];
        } elseif (!is_array($hit) && method_exists($hit, 'getId')) {
            return $hit->getId();
        } elseif (!is_array($hit) && property_exists($hit, 'id')) {
            return $hit->id;
        } elseif (array_key_exists('id', $hit)) {
            return $hit['id'];
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

        $max = $this->maxMatches ?? config('manticore.max_matches');
        if ($max !== null) {
            $search->option('max_matches', $max);
        }

        return $search;
    }

    protected function makeFilter(string $field, string $operator, mixed $value): \Manticoresearch\Query
    {
        return match (strtolower($operator)) {
            '=', '=='   => new \Manticoresearch\Query\Equals($field, $value),
            '!=', '<>'  => new \Manticoresearch\Query\Equals($field, $value),
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
        $clause = ManticoreQueryCompile::toSqlWhereClause($this->must, $this->should, $this->mustNot, $this->match);
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
        $max = $this->maxMatches ?? config('manticore.max_matches');
        return $max ? "OPTION max_matches={$max}" : '';
    }

    protected function buildLimitClause(): string
    {
        $limit  = isset($this->limit)  ? $this->limit : null;
        $offset = isset($this->offset) ? $this->offset : null;

        if ($limit !== null && $offset !== null) {
            return "LIMIT {$limit}, {$offset}";
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
