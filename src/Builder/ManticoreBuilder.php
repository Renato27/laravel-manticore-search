<?php

namespace ManticoreLaravel\Builder;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use ManticoreLaravel\Builder\Utils\ManticoreQueryCompile;

class ManticoreBuilder extends Abstracts\ManticoreBuilderAbstract
{
    public function rawQuery(string $raw, $rawMode = false): static
    {
        $this->rawQuery = $raw;
        $this->rawQueryMode = $rawMode;
        return $this;
    }

    public function match(string $value): static
    {
        $this->match = $value;
        return $this;
    }

    public function maxMatches(int $value): static
    {
        $this->maxMatches = $value;
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

        $this->must[] = $this->makeFilter($field, $operator, $value);
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

        $this->should[] = $this->makeFilter($field, $operator, $value);
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
        return $this;
    }

    public function whereIn(string $field, array $values): static
    {
        $this->must[] = new \Manticoresearch\Query\In($field, $values);
        return $this;
    }

    public function whereNotIn(string $field, array $values): static
    {
        $this->mustNot[] = new \Manticoresearch\Query\In($field, $values);
        return $this;
    }

    public function whereBetween(string $field, array $range): static
    {
        $this->must[] = new \Manticoresearch\Query\Range($field, [
            'gte' => $range[0],
            'lte' => $range[1]
        ]);
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
        return $this;
    }

    public function orderBy(string $field, string $dir = 'asc'): static
    {
        $this->sort[] = [$field => $dir];
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
        return $this->resolveResults($results);
    }

    public function toSql(): string
    {
        $compiled = $this->search()->compile();
        return ManticoreQueryCompile::toRawSql($compiled);
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
        }else{
            $resultSet = $this->search()->get();
            $results = $this->resolveResults($resultSet);
        }
       
        return new LengthAwarePaginator(
            $results,
            $resultSet ? $resultSet->getTotal() : $results->count(),
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