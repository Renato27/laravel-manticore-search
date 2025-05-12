<?php

namespace ManticoreLaravel\Builder\Abstracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use ManticoreLaravel\Builder\Utils\ManticoreQueryCompile;
use Manticoresearch\Client;
use Manticoresearch\Search;

abstract class ManticoreBuilderAbstract
{
    protected $model;
    protected $match = null;
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

    private function getClient(): Client
    {
        return new Client([
            'host' => config('manticore.host'),
            'port' => config('manticore.port'),
            'username' => config('manticore.username'),
            'password' => config('manticore.password'),
            'transport' => config('manticore.transport'),
            'timeout' => config('manticore.timeout'),
            'persistent' => config('manticore.persistent'),
        ]);
    }

    protected function fetchRawQuery(): Collection
    {
        $client = $this->getClient();

        $results = $client->sql($this->rawQuery, $this->rawQueryMode);
        return $this->resolveResults($results);
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
        return collect($hits)->map(function ($hit) {
            $model = clone $this->model;
            $model->forceFill($hit->getData() ?? []);
            try {
                $highlight = $hit->getHighlight();
                if (!empty($highlight)) {
                    $model->highlight = $highlight;
                }
            } catch (\Throwable $e) {
            }
            $model->exists = true;
            return $model;
        });
    }

    private function resolveResultsArray($results): Collection
    {
        $hits = $results['hits']['hits'] ?? [];
        $hits = iterator_to_array($hits);

        return collect($hits)->map(function ($hit) {
            $model = clone $this->model;
            $model->forceFill($hit['_source'] ?? $hit);
            $model->exists = true;
            return $model;
        });
    }

    protected function search(): Search
    {
        $client = $this->getClient();

        $search = new \Manticoresearch\Search($client);
        $this->applyIndex($search);

        $bool = new \Manticoresearch\Query\BoolQuery();

        if ($this->match) {
            $match = new \Manticoresearch\Query\MatchQuery($this->match, '*');
            $bool->must($match);
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

    protected function buildHavingClause(): string
    {
        return !empty($this->having) ? 'HAVING ' . implode(' AND ', $this->having) : '';
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
        $having   = $this->buildHavingClause();
        $limit    = $this->buildLimitClause();

        return trim("SELECT {$select} FROM {$this->resolveIndexName()} {$where} {$groupBy} {$having} {$limit}");
    }
}
