<?php

namespace ManticoreLaravel\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;

interface ManticoreBuilderContract
{
    public function match(string $keywords, ?string $field = null, string $boolean = 'AND'): static;
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static;
    public function orWhere(string $field, mixed $operatorOrValue, mixed $value = null): static;
    public function whereNot(string $field, mixed $operatorOrValue, mixed $value = null): static;
    public function whereIn(string $field, array $values): static;
    public function whereNotIn(string $field, array $values): static;
    public function whereBetween(string $field, array $range): static;
    public function whereNull(string $field): static;
    public function whereNotNull(string $field): static;
    public function whereRaw(string $sql, string $boolean = 'AND'): static;
    public function whereGeoDistance(string $field, float $lat, float $lon, float $distanceMeters): static;
    public function orderBy(array|string $column, ?string $direction = null): static;
    public function limit(int $limit): static;
    public function offset(int $offset): static;
    public function forPage(int $page, int $perPage): static;
    public function select(array|string $fields): static;
    public function groupBy(array|string $fields): static;
    public function having(array|string $conditions): static;
    public function with(array|string ...$relations): static;
    public function option(string $key, mixed $value): static;
    public function maxMatches(int $value): static;
    public function withHighlight(): static;
    public function aggregate(string $name, array $aggregation): static;
    public function expression(string $name, mixed $exp): static;
    public function rawQuery(string $raw, bool $rawMode = false): static;
    public function useIndex(array|string $indexes): static;
    public function usingConnection(string $name): static;
    public function when(mixed $condition, callable $callback, ?callable $default = null): static;
    public function tap(callable $callback): static;
    public function get(): Collection;
    public function first(): mixed;
    public function last(): mixed;
    public function count(): int;
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator;
    public function consolidateBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true): mixed;
    public function consolidateAllBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true): SupportCollection;
    public function paginateConsolidatedBy(string $groupField, int $perPage = 15, string $pageName = 'page', ?int $page = null, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true): LengthAwarePaginator;
    public function getFacets(): array;
    public function flushPaginationTotalCache(): static;
    public function toSql(): string;
    public function toArray(): array;
    public function toJson(int $options = 0): string;
    public function dump(): static;
    public function dd(): never;
    public function chunk(int $size, callable $callback): bool;
    public function builder(): static;
    public function getSearchInstance(): \Manticoresearch\Search;
    public function getTableInstance(): \Manticoresearch\Table;
    public function getClientInstance(): \Manticoresearch\Client;
}
