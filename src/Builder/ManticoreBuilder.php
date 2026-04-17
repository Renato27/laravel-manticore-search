<?php

namespace ManticoreLaravel\Builder;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use ManticoreLaravel\Contracts\ManticoreBuilderContract;

class ManticoreBuilder extends Abstracts\ManticoreBuilderAbstract implements ManticoreBuilderContract
{
    // =========================================================================
    // Constraint / filter methods
    // =========================================================================

    /**
     * Add a full-text MATCH condition.
     *
     * @param  string  $keywords  The search keywords.
     * @param  string|null  $field  Field to search in (e.g. 'title'). Defaults to all fields (*).
     * @param  string  $boolean  'AND' or 'OR' connector with previous match.
     */
    public function match(string $keywords, ?string $field = null, string $boolean = 'AND'): static
    {
        $this->match[] = [
            'field'    => $field ?: '*',
            'keywords' => $keywords,
            'boolean'  => $boolean,
        ];

        return $this;
    }

    /**
     * Add a WHERE condition. Supports 2-arg shorthand (field, value) or 3-arg (field, operator, value).
     *
     * Supported operators: =, ==, !=, <>, >, >=, <, <=
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $value] = $this->parseWhereArgs(func_num_args(), $operatorOrValue, $value);

        if (in_array(strtolower($operator), ['!=', '<>'], true)) {
            $filter = $this->makeFilter($field, '=', $value);
            $this->addCondition($filter, 'and', true);
        } else {
            $filter = $this->makeFilter($field, $operator, $value);
            $this->addCondition($filter, 'and', false);
        }

        return $this;
    }

    /**
     * Add an OR WHERE condition.
     */
    public function orWhere(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $value] = $this->parseWhereArgs(func_num_args(), $operatorOrValue, $value);
        $filter = $this->makeFilter($field, $operator, $value);
        $this->addCondition($filter, 'or', false);

        return $this;
    }

    /**
     * Add a negated WHERE condition (NOT equals / NOT range).
     */
    public function whereNot(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $value] = $this->parseWhereArgs(func_num_args(), $operatorOrValue, $value);
        $filter = $this->makeFilter($field, $operator, $value);
        $this->addCondition($filter, 'and', true);

        return $this;
    }

    /**
     * Add a WHERE IN condition.
     *
     * @param  array<int, mixed>  $values
     */
    public function whereIn(string $field, array $values): static
    {
        $filter = new \Manticoresearch\Query\In($field, $values);
        $this->addCondition($filter, 'and', false);

        return $this;
    }

    /**
     * Add a WHERE NOT IN condition.
     *
     * @param  array<int, mixed>  $values
     */
    public function whereNotIn(string $field, array $values): static
    {
        $filter = new \Manticoresearch\Query\In($field, $values);
        $this->addCondition($filter, 'and', true);

        return $this;
    }

    /**
     * Add a WHERE BETWEEN condition.
     *
     * @param  array{0: mixed, 1: mixed}  $range  [min, max]
     */
    public function whereBetween(string $field, array $range): static
    {
        $filter = new \Manticoresearch\Query\Range($field, [
            'gte' => $range[0],
            'lte' => $range[1],
        ]);
        $this->addCondition($filter, 'and', false);

        return $this;
    }

    /**
     * Add a WHERE field IS NULL condition (SQL mode only).
     */
    public function whereNull(string $field): static
    {
        $this->addRawCondition("`{$field}` IS NULL", 'AND');

        return $this;
    }

    /**
     * Add a WHERE field IS NOT NULL condition (SQL mode only).
     */
    public function whereNotNull(string $field): static
    {
        $this->addRawCondition("`{$field}` IS NOT NULL", 'AND');

        return $this;
    }

    /**
     * Inject a raw SQL fragment into the WHERE clause (SQL mode only).
     * This allows using Manticore-specific functions not covered by other methods.
     *
     * @param  string  $sql  Raw SQL fragment, e.g. "geodist(lat, lon, 48.8, 2.3) < 10000"
     * @param  string  $boolean  'AND' or 'OR'
     */
    public function whereRaw(string $sql, string $boolean = 'AND'): static
    {
        $this->addRawCondition($sql, strtoupper($boolean));

        return $this;
    }

    /**
     * Add a geo-distance filter.
     */
    public function whereGeoDistance(string $field, float $lat, float $lon, float $distanceMeters): static
    {
        $filter = new \Manticoresearch\Query\Distance([
            $field     => ['lat' => $lat, 'lon' => $lon],
            'distance' => $distanceMeters,
        ]);
        $this->addCondition($filter, 'and', false);

        return $this;
    }

    // =========================================================================
    // Ordering, limiting, selecting
    // =========================================================================

    /**
     * Add an ORDER BY clause.
     * Accepts a column + direction, or an array of [column => direction] pairs.
     */
    public function orderBy(array|string $column, ?string $direction = null): static
    {
        if (is_array($column)) {
            foreach ($column as $col => $dir) {
                if (is_int($col)) {
                    $this->sort[] = [(string) $dir => 'asc'];
                } else {
                    $d            = strtolower((string) $dir) === 'desc' ? 'desc' : 'asc';
                    $this->sort[] = [(string) $col => $d];
                }
            }

            return $this;
        }

        $dir          = strtolower((string) ($direction ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $this->sort[] = [(string) $column => $dir];

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

    /**
     * Set limit + offset for the given page number.
     */
    public function forPage(int $page, int $perPage): static
    {
        return $this->offset(max(0, ($page - 1) * $perPage))->limit($perPage);
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

    // =========================================================================
    // Relation eager loading
    // =========================================================================

    /**
     * Eager-load Eloquent relations on results.
     *
     * Supports:
     *   - Simple string: ->with('relation')
     *   - Column-constrained: ->with('relation:col1,col2')
     *   - Array with closures: ->with(['relation' => fn($q) => $q->select(...)])
     */
    public function with(array|string ...$relations): static
    {
        $push = function (string $name, mixed $val = null) {
            $closure = null;

            if (str_contains($name, ':')) {
                [$relation, $cols] = explode(':', $name, 2);
                $colsArr           = array_values(array_filter(array_map('trim', explode(',', $cols))));
                $closure           = function ($q) use ($colsArr) {
                    $cols     = $colsArr;
                    $pk       = $q->getModel()->getKeyName();
                    $fillable = $q->getModel()->getFillable();

                    if ($pk && !in_array($pk, $cols, true) && in_array($pk, $fillable, true)) {
                        $cols[] = $pk;
                    }

                    $q->select($cols);
                };
                $name    = $relation;
            } elseif ($val instanceof \Closure) {
                $closure = $val;
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

    // =========================================================================
    // Query options and metadata
    // =========================================================================

    public function option(string $key, mixed $value): static
    {
        $this->option[$key] = $value;

        return $this;
    }

    public function maxMatches(int $value): static
    {
        $this->option['max_matches'] = $value;

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

    /**
     * Add a Manticore expression / script field to the query.
     */
    public function expression(string $name, mixed $exp): static
    {
        $this->scriptFields[$name] = $exp;

        return $this;
    }

    // =========================================================================
    // Connection & index overrides
    // =========================================================================

    /**
     * Set a raw SQL query string, bypassing the builder entirely.
     *
     * @param  bool  $rawMode  When true, use raw SQL response mode (returns array, not ResultSet).
     */
    public function rawQuery(string $raw, bool $rawMode = false): static
    {
        $this->rawQuery     = $raw;
        $this->rawQueryMode = $rawMode;

        return $this;
    }

    /**
     * Override the index/table name for this query.
     */
    public function useIndex(array|string $indexes): static
    {
        $this->indexOverride = $indexes;
        $this->flushResolvedIndexState();

        return $this;
    }

    /**
     * Use a named Manticore connection for this query.
     * The name must exist in manticore.connections config.
     */
    public function usingConnection(string $name): static
    {
        $this->connectionName = $name;
        $this->flushResolvedConnectionState();

        return $this;
    }

    // =========================================================================
    // Conditional / utility chainables
    // =========================================================================

    /**
     * Apply a callback only when $condition is truthy.
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $callback($this);
        } elseif ($default !== null) {
            $default($this);
        }

        return $this;
    }

    /**
     * Tap the builder with a callback without breaking the chain.
     */
    public function tap(callable $callback): static
    {
        $callback($this);

        return $this;
    }

    // =========================================================================
    // Query execution
    // =========================================================================

    protected function fetchSqlQuery(): Collection
    {
        $sql       = $this->buildSqlQuery();
        $resultSet = $this->executeSqlQuery($sql);
        $rows      = $this->extractRawRows($resultSet);
        $models    = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    /**
     * Execute the query and return an Eloquent Collection of hydrated models.
     */
    public function get(): Collection
    {
        if ($this->rawQuery) {
            return $this->fetchRawQuery();
        }

        if ($this->usesSqlQueryMode()) {
            return $this->fetchSqlQuery();
        }

        $results = $this->search()->get();
        $rows    = $this->extractRawRows($results);
        $models  = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    /**
     * Return the first result, or null.
     */
    public function first(): mixed
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Return the last result from the current result set.
     */
    public function last(): mixed
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

    public function toJson(int $options = 0): string
    {
        return $this->get()->toJson($options);
    }

    /**
     * Dump the compiled SQL query and options, then continue the chain.
     */
    public function dump(): static
    {
        dump($this->toSql(), ['options' => $this->option]);

        return $this;
    }

    /**
     * Dump the compiled SQL query and options, then die.
     */
    public function dd(): never
    {
        dd($this->toSql(), ['options' => $this->option]);
    }

    /**
     * Iterate over results in chunks, calling $callback for each chunk.
     * Returns false if the callback returns false (stops iteration).
     */
    public function chunk(int $size, callable $callback): bool
    {
        $page = 1;

        do {
            $results = (clone $this)->forPage($page, $size)->get();

            if ($results->isEmpty()) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($results->count() >= $size);

        return true;
    }

    /**
     * Extract the raw rows for the current query without hydrating to models.
     */
    protected function getRawRowsForCurrentQuery(): array
    {
        if ($this->rawQuery) {
            $results = $this->executeSqlQuery($this->rawQuery, $this->rawQueryMode);

            return $this->extractRawRows($results);
        }

        if ($this->usesSqlQueryMode()) {
            $sql     = $this->buildSqlQuery();
            $results = $this->executeSqlQuery($sql);

            return $this->extractRawRows($results);
        }

        $results = $this->search()->get();

        return $this->extractRawRows($results);
    }

    public function toSql(): string
    {
        return $this->buildSqlQuery();
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    /**
     * Paginate the results and return a LengthAwarePaginator.
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page             = $this->resolvePageFromRequestInput($pageName, $page);
        $paginatorOptions = $this->resolvePaginatorOptions($pageName);
        $offset           = max(0, ($page - 1) * $perPage);

        if (!array_key_exists('max_matches', $this->option)) {
            $this->option('max_matches', $this->maxMatchesForOffsetWindow($offset, $perPage));
        }

        $this->limit($perPage)->offset($offset);

        if ($this->rawQuery) {
            $results = $this->fetchRawQuery();
            $total   = $results->count();
        } else {
            $total = $this->getTotalMatches();

            if ($this->usesSqlQueryMode()) {
                $resultSet = $this->executeSqlQuery($this->buildSqlQuery());
            } else {
                $resultSet = $this->search()->get();
            }

            $rows    = $this->extractRawRows($resultSet);
            $results = $this->applyEloquentWith($this->hydrateModelsFromRows($rows));

            if ($total === 0 && $results->count() > 0) {
                $total = $results->count();
            }
        }

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            $paginatorOptions
        );
    }

    /**
     * Flush the pagination total cache for the current query context.
     */
    public function flushPaginationTotalCache(): static
    {
        Cache::forget($this->getPaginationCacheKey());

        return $this;
    }

    /**
     * Resolve pagination input from request, applying context cache if present.
     */
    public static function resolvePaginationInputFromRequest(string $pageName = 'page', ?Request $request = null): array
    {
        $request = $request ?? (app()->bound('request') ? app('request') : null);

        if (!$request instanceof Request) {
            return [];
        }

        $contextKey = (string) config('manticore.pagination.context_key', '_mctx');
        $cachePrefix = (string) config('manticore.pagination.cache_prefix', 'manticore:pagination:');
        $input      = $request->input();
        $contextId  = $input[$contextKey] ?? null;

        if (is_string($contextId) && $contextId !== '') {
            $cached = Cache::get($cachePrefix . $contextId);

            if (is_array($cached) && !empty($cached)) {
                $input = array_replace_recursive($cached, $input);
            }
        }

        return $input;
    }

    // =========================================================================
    // Consolidation (group-merge) API
    // =========================================================================

    /**
     * Execute the query and return the first consolidated result for the given group field.
     */
    public function consolidateBy(
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): mixed {
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

    /**
     * Execute the query and return all consolidated results for the given group field.
     */
    public function consolidateAllBy(
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): SupportCollection {
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

    /**
     * Alias for consolidateAllBy (kept for backward compatibility).
     */
    public function getConsolidatedBy(
        string $groupField,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): SupportCollection {
        return $this->consolidateAllBy($groupField, $historyAttribute, $preserveGroupFieldInHistory);
    }

    /**
     * Paginate consolidated results for the given group field.
     */
    public function paginateConsolidatedBy(
        string $groupField,
        int $perPage = 15,
        string $pageName = 'page',
        ?int $page = null,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): LengthAwarePaginator {
        $page             = $this->resolvePageFromRequestInput($pageName, $page);
        $paginatorOptions = $this->resolvePaginatorOptions($pageName);

        $data = $this->canUseOptimizedConsolidatedPagination()
            ? $this->paginateConsolidatedOptimized(
                $groupField, $perPage, $page, $historyAttribute, $preserveGroupFieldInHistory
            )
            : $this->paginateConsolidatedFallback(
                $groupField, $perPage, $page, $historyAttribute, $preserveGroupFieldInHistory
            );

        if (empty($data['rows'])) {
            return new LengthAwarePaginator(
                collect(),
                (int) ($data['total'] ?? 0),
                $perPage,
                $page,
                $paginatorOptions
            );
        }

        $models  = $this->hydrateModelsFromRows($data['rows']);
        $results = $this->applyEloquentWith($models);

        return new LengthAwarePaginator(
            $results,
            (int) ($data['total'] ?? $results->count()),
            $perPage,
            $page,
            $paginatorOptions
        );
    }

    // =========================================================================
    // Consolidation pagination internals
    // =========================================================================

    /**
     * True when the optimized 2-query consolidated pagination can be used.
     * Override and return false in subclasses that override getRawRowsForCurrentQuery.
     */
    protected function canUseOptimizedConsolidatedPagination(): bool
    {
        return !$this->rawQuery && empty($this->groupBy) && empty($this->having) && empty($this->select);
    }

    protected function groupValueKey(mixed $value): string
    {
        if ($value === null) {
            return '__null__';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return md5(serialize($value));
    }

    protected function fetchConsolidatedPageKeyRows(string $groupField, int $perPage, int $page): array
    {
        $offset         = max(0, ($page - 1) * $perPage);
        $groupedBuilder = clone $this;

        $groupedBuilder->groupBy([$groupField]);
        $groupedBuilder->select([$groupField]);
        $groupedBuilder->limit($perPage)->offset($offset);

        if (!array_key_exists('max_matches', $groupedBuilder->option)) {
            $groupedBuilder->option('max_matches', 1000000);
        }

        $resultSet = $groupedBuilder->executeSqlQuery($groupedBuilder->buildSqlQuery());
        $rows      = $groupedBuilder->extractRawRows($resultSet);
        $total     = $groupedBuilder->extractTotalFromResultSet($resultSet, count($rows));

        return ['rows' => $rows, 'total' => $total];
    }

    protected function fetchConsolidatedHistoryRows(string $groupField, array $groupValues): array
    {
        if (empty($groupValues)) {
            return [];
        }

        $historyBuilder        = clone $this;
        $unlimitedMaxMatches   = (int) config('manticore.unlimited_max_matches', 1000000);

        $historyBuilder->limit        = $unlimitedMaxMatches;
        $historyBuilder->offset       = 0;
        $historyBuilder->sort         = [];
        $historyBuilder->groupBy      = [];
        $historyBuilder->having       = [];
        $historyBuilder->select       = [];
        $historyBuilder->whereIn($groupField, $groupValues);

        if (!array_key_exists('max_matches', $historyBuilder->option)) {
            $historyBuilder->option('max_matches', $unlimitedMaxMatches);
        }

        if ($historyBuilder->usesSqlQueryMode()) {
            $resultSet = $historyBuilder->executeSqlQuery($historyBuilder->buildSqlQuery());
            return $historyBuilder->extractRawRows($resultSet);
        }

        $results = $historyBuilder->search()->get();
        return $historyBuilder->extractRawRows($results);
    }

    protected function reorderConsolidatedRowsByGroupField(array $consolidatedRows, string $groupField, array $orderedGroupValues): array
    {
        $rowsByKey = [];

        foreach ($consolidatedRows as $row) {
            $rowsByKey[$this->groupValueKey($this->resolveRowFieldValue($row, $groupField))] = $row;
        }

        $ordered = [];

        foreach ($orderedGroupValues as $groupValue) {
            $key = $this->groupValueKey($groupValue);

            if (isset($rowsByKey[$key])) {
                $ordered[] = $rowsByKey[$key];
            }
        }

        return $ordered;
    }

    protected function paginateConsolidatedOptimized(
        string $groupField,
        int $perPage,
        int $page,
        string $historyAttribute,
        bool $preserveGroupFieldInHistory
    ): array {
        $groupedPage   = $this->fetchConsolidatedPageKeyRows($groupField, $perPage, $page);
        $pageGroupRows = $groupedPage['rows'];
        $total         = $groupedPage['total'];

        if (empty($pageGroupRows)) {
            return ['rows' => [], 'total' => $total];
        }

        $orderedGroupValues = [];

        foreach ($pageGroupRows as $row) {
            $groupValue = $this->resolveRowFieldValue($row, $groupField);
            if ($groupValue !== null) {
                $orderedGroupValues[] = $groupValue;
            }
        }

        $historyRows      = $this->fetchConsolidatedHistoryRows($groupField, $orderedGroupValues);
        $consolidatedRows = $this->consolidateRawRows($historyRows, $groupField, $historyAttribute, $preserveGroupFieldInHistory);

        return [
            'rows'  => $this->reorderConsolidatedRowsByGroupField($consolidatedRows, $groupField, $orderedGroupValues),
            'total' => $total,
        ];
    }

    protected function paginateConsolidatedFallback(
        string $groupField,
        int $perPage,
        int $page,
        string $historyAttribute,
        bool $preserveGroupFieldInHistory
    ): array {
        $offset  = max(0, ($page - 1) * $perPage);
        $builder = clone $this;
        $builder->limit($perPage)->offset($offset);

        $resultSet  = $builder->executeSqlQuery($builder->buildSqlQuery());
        $rows       = $builder->extractRawRows($resultSet);
        $total      = $this->getTotalMatches() ?: $builder->extractTotalFromResultSet($resultSet, count($rows));

        $orderedGroupValues = [];
        foreach ($rows as $row) {
            $groupValue = $this->resolveRowFieldValue($row, $groupField);
            if ($groupValue !== null) {
                $orderedGroupValues[] = $groupValue;
            }
        }

        $historyRows           = $this->fetchConsolidatedHistoryRows($groupField, $orderedGroupValues);
        $pageConsolidatedRows  = $this->consolidateRawRows($historyRows, $groupField, $historyAttribute, $preserveGroupFieldInHistory);

        return [
            'rows'  => $this->reorderConsolidatedRowsByGroupField($pageConsolidatedRows, $groupField, $orderedGroupValues),
            'total' => $total,
        ];
    }

    // =========================================================================
    // Facets / aggregations
    // =========================================================================

    /**
     * Execute the query and return the facet aggregation results.
     *
     * @throws \LogicException
     */
    public function getFacets(): array
    {
        if ($this->rawQuery) {
            throw new \LogicException('Facets are not supported in rawQuery mode.');
        }

        $result = $this->search()->get();

        return $result->getFacets() ?? [];
    }

    // =========================================================================
    // Instance accessors
    // =========================================================================

    public function getSearchInstance(): \Manticoresearch\Search
    {
        return $this->search();
    }

    public function getTableInstance(): \Manticoresearch\Table
    {
        return $this->getTable();
    }

    public function getClientInstance(): \Manticoresearch\Client
    {
        return $this->getClient();
    }

    /**
     * Returns $this for use in expression chains.
     */
    public function builder(): static
    {
        return $this;
    }

    // =========================================================================
    // Collection helpers
    // =========================================================================

    public function pluck(string $field): Collection
    {
        return $this->get()->pluck($field);
    }
}
