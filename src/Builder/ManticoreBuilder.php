<?php

namespace ManticoreLaravel\Builder;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ManticoreBuilder extends Abstracts\ManticoreBuilderAbstract
{
    public function rawQuery(string $raw, bool $rawMode = false): static
    {
        $this->rawQuery = $raw;
        $this->rawQueryMode = $rawMode;
        return $this;
    }

    public function option(string $key, mixed $value): static {
        $this->option[$key] = $value;
        return $this;
    }

    public function useIndex(array|string $indexes): static
    {
        $this->indexOverride = $indexes;
        $this->flushResolvedIndexState();

        return $this;
    }

    public function with(array|string ...$relations): static
    {
        $push = function (string $name, $val = null) {
            $closure = null;

            if (str_contains($name, ':')) {
                [$relation, $cols] = explode(':', $name, 2);
                $colsArr = array_values(array_filter(array_map('trim', explode(',', $cols))));
                $closure = function ($q) use ($colsArr) {
                    $cols = $colsArr;
                    $pk = $q->getModel()->getKeyName();
                    $fillable = $q->getModel()->getFillable();
                    if ($pk && !in_array($pk, $cols, true) && in_array($pk, $fillable, true)) {
                        $cols[] = $pk;
                    }

                    $q->select($cols);
                };

                $name = $relation;
            } elseif ($val instanceof \Closure) {
                $closure = $val;
            } elseif (is_array($val) && empty($val)) {
                $closure = null;
            }

            $this->eagerQueue[] = ['name' => $name, 'closure' => $closure];
        };

        foreach ($relations as $rel) {
            if (is_string($rel)) {
                $push($rel);
            } elseif (is_array($rel)) {
                foreach ($rel as $k => $v) {
                    if (is_int($k) && is_string($v)) {
                        $push($v);
                    } elseif (is_string($k)) {
                        $push($k, $v);
                    }
                }
            }
        }

        return $this;
    }

    public function match(string $keywords, ?string $field = null): static
    {
        $this->match[] = [
            'field' => $field,
            'keywords' => $keywords
        ];
        return $this;
    }

    public function maxMatches(int $value): static
    {
        $this->option['max_matches'] = $value;
        return $this;
    }

    /**
     * Select a named Manticore connection for this query.
     * The name must correspond to a key in manticore.connections.
     */
    public function usingConnection(string $name): static
    {
        $this->connectionName = $name;
        $this->flushResolvedConnectionState();

        return $this;
    }

    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        if (in_array(strtolower($operator), ['!=', '<>'])) {
            $this->mustNot[] = $this->makeFilter($field, '=', $value);
             $this->whereSequence[] = [
                'boolean' => 'and',
                'negated' => true,
                'condition' => $this->makeFilter($field, '=', $value),
            ];
        } else {
            $this->must[] = $this->makeFilter($field, $operator, $value);
            $this->whereSequence[] = [
                'boolean' => 'and',
                'negated' => false,
                'condition' => $this->makeFilter($field, $operator, $value),
            ];

        }
        
        return $this;
    }

    public function orWhere(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        $filter = $this->makeFilter($field, $operator, $value);

        if (!empty($this->must) && empty($this->should)) {
            $this->should[] = array_pop($this->must);
        }

        $this->should[] = $filter;

        $this->whereSequence[] = [
            'boolean' => 'or',
            'negated' => false,
            'condition' => $filter,
        ];

        return $this;
    }

    public function whereNot(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        $this->mustNot[] = $this->makeFilter($field, $operator, $value);
        $this->whereSequence[] = [
            'boolean' => 'and',
            'negated' => true,
            'condition' => $this->makeFilter($field, $operator, $value),
        ];
        return $this;
    }

    public function whereIn(string $field, array $values): static
    {
        $this->must[] = new \Manticoresearch\Query\In($field, $values);
        $this->whereSequence[] = [
            'boolean' => 'and',
            'negated' => false,
            'condition' => new \Manticoresearch\Query\In($field, $values),
        ];
        return $this;
    }

    public function whereNotIn(string $field, array $values): static
    {
        $this->mustNot[] = new \Manticoresearch\Query\In($field, $values);
        $this->whereSequence[] = [
            'boolean' => 'and',
            'negated' => true,
            'condition' => new \Manticoresearch\Query\In($field, $values),
        ];
        return $this;
    }

    public function whereBetween(string $field, array $range): static
    {
        $this->must[] = new \Manticoresearch\Query\Range($field, [
            'gte' => $range[0],
            'lte' => $range[1]
        ]);
        $this->whereSequence[] = [
            'boolean' => 'and',
            'negated' => false,
            'condition' => new \Manticoresearch\Query\Range($field, [
                'gte' => $range[0],
                'lte' => $range[1]
            ]),
        ];
        return $this;
    }

    public function whereGeoDistance(string $field, float $lat, float $lon, float $distanceMeters): static
    {
        $this->must[] = new \Manticoresearch\Query\Distance([
            $field => [
                'lat' => $lat,
                'lon' => $lon,
            ],
            'distance' => $distanceMeters
        ]);
        $this->whereSequence[] = [
            'boolean' => 'and',
            'negated' => false,
            'condition' => new \Manticoresearch\Query\Distance([
                $field => [
                    'lat' => $lat,
                    'lon' => $lon,
                ],
                'distance' => $distanceMeters
            ]),
        ];
        return $this;
    }

    public function orderBy($column, $direction = null): static
    {
        if (is_array($column)) {
            foreach ($column as $col => $dir) {
                if (is_int($col)) {
                    $this->sort[] = [(string)$dir => 'asc'];
                } else {
                    $d = strtolower((string)$dir) === 'desc' ? 'desc' : 'asc';
                    $this->sort[] = [(string)$col => $d];
                }
            }
            return $this;
        }

        $dir = strtolower((string)($direction ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $this->sort[] = [(string)$column => $dir];

        return $this;
    }

    public function expression(string $name, mixed $exp): static
    {
        $this->scriptFields[$name] = $exp;
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function withHighlight(): static
    {
        $this->highlight = true;
        return $this;
    }

    public function aggregate(string $name, array $aggregation): static
    {
        $this->aggregations[$name] = $aggregation;
        return $this;
    }

    public function select(array|string $fields): static
    {
        $this->select = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    public function groupBy(array|string $fields): static
    {
        $this->groupBy = is_array($fields) ? $fields : [$fields];
        return $this;
    }

    public function having(array|string $conditions): static
    {
        $this->having = array_merge(
            $this->having,
            is_array($conditions) ? $conditions : [$conditions]
        );

        return $this;
    }

    protected function fetchSqlQuery(): Collection
    {
        $sql = $this->buildSqlQuery();
        return $this->rawQuery($sql)->fetchRawQuery();
    }

    public function get(): Collection
    {
        if($this->rawQuery) {
            return $this->fetchRawQuery();
        }

        if (!empty($this->groupBy) || !empty($this->having) || !empty($this->select)) {
            return $this->fetchSqlQuery();
        }

        $results = $this->search()->get();
        $rows = $this->extractRawRows($results);
        $models = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    public function consolidateBy(
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ) {
        $rows = $this->getRawRowsForCurrentQuery();

        if (empty($rows)) {
            return null;
        }

        $consolidatedRows = $this->consolidateRawRows(
            $rows,
            $groupField,
            $historyAttribute,
            $preserveGroupFieldInHistory
        );

        if (empty($consolidatedRows)) {
            return null;
        }
    
        $models = $this->hydrateModelsFromRows([$consolidatedRows[0]]);
        return $this->applyEloquentWith($models)->first();
    }

    public function consolidateAllBy(
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): Collection {
        $rows = $this->getRawRowsForCurrentQuery();

        if (empty($rows)) {
            return collect();
        }

        $consolidatedRows = $this->consolidateRawRows(
            $rows,
            $groupField,
            $historyAttribute,
            $preserveGroupFieldInHistory
        );

        $models = $this->hydrateModelsFromRows($consolidatedRows);

        return $this->applyEloquentWith($models);
    }

    protected function getRawRowsForCurrentQuery(): array
    {
        if ($this->rawQuery) {
            $client = $this->getClient();
            $results = $client->sql($this->rawQuery, $this->rawQueryMode);

            return $this->extractRawRows($results);
        }

        if (!empty($this->groupBy) || !empty($this->having) || !empty($this->select)) {
            $client = $this->getClient();
            $sql = $this->buildSqlQuery();
            $results = $client->sql($sql, false);

            return $this->extractRawRows($results);
        }

        $results = $this->search()->get();

        return $this->extractRawRows($results);
    }

    public function toSql(): string
    {
        return $this->buildSqlQuery();
    }

    public function first()
    {
        return $this->limit(1)->get()->first();
    }

    public function last()
    {
        return $this->get()->last();
    }

    public function count(): int
    {
        return $this->get()->count();
    }

    public function toArray(): array
    {
        return $this->get()->toArray();
    }

    public function toJson($options = 0): string
    {
        return $this->get()->toJson($options);
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);
        $this->limit($perPage)->offset(($page - 1) * $perPage);

        if ($this->rawQuery) {
            $results = $this->fetchRawQuery();
            $total = $results->count();
        }else{
            $resultSet = $this->search()->get();
            $rows = $this->extractRawRows($resultSet);
            $results = $this->applyEloquentWith($this->hydrateModelsFromRows($rows));
            $total = $resultSet ? $resultSet->getTotal() : $results->count();
        }

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    public function getFacets(): array
    {
        if ($this->rawQuery) {
            throw new \LogicException('Facets are not supported in rawQuery mode.');
        }

        $result = $this->search()->get();
        return $result->getFacets() ?? [];
    }

    public function getSearchInstance(): \Manticoresearch\Search
    {
        return $this->search();
    }

    public function builder(): static
    {
        return $this;
    }

    public function getTableInstance(): \Manticoresearch\Table
    {
        return $this->getTable();
    }

    public function getClientInstance(): \Manticoresearch\Client
    {
        return $this->getClient();
    }

    public function when($condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $callback($this);
        } elseif ($default) {
            $default($this);
        }
        return $this;
    }

    public function pluck(string $field): Collection
    {
        return $this->get()->pluck($field);
    }
}
