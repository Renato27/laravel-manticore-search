<?php

namespace ManticoreLaravel\Builder;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ManticoreBuilder extends Abstracts\ManticoreBuilderAbstract
{
    protected function activeConnectionConfigKey(): string
    {
        return $this->connectionName
            ?? (string) config('manticore.default', 'default');
    }

    protected function configuredMaxMatches(): int
    {
        $value = (int) ($this->resolveConnectionConfig()['max_matches'] ?? 1000);

        return $value > 0 ? $value : 1000;
    }

    protected function maxMatchesForOffsetWindow(int $offset, int $perPage): int
    {
        return max($this->configuredMaxMatches(), $offset + $perPage);
    }

    /**
     * Compute a hash of the current filter context to identify identical queries.
     * Used for pagination total caching.
     */
    protected function computeFiltersContextHash(): string
    {
        $signatureBuilder = clone $this;
        $signatureBuilder->limit = null;
        $signatureBuilder->offset = null;
        $signatureBuilder->sort = [];

        $signatureOptions = $signatureBuilder->option;
        unset($signatureOptions['max_matches']);

        $signature = [
            'connectionName' => $signatureBuilder->connectionName,
            'index' => $signatureBuilder->resolveIndexName(),
            'rawQueryMode' => $signatureBuilder->rawQueryMode,
            'rawQuery' => $signatureBuilder->rawQuery,
            'sql' => $signatureBuilder->rawQuery ? null : $signatureBuilder->buildSqlQuery(),
            'option' => $signatureOptions,
        ];

        return md5(serialize($signature));
    }

    /**
     * Get the cache key for pagination total based on filters context.
     */
    protected function getPaginationCacheKey(string $suffix = ''): string
    {
        $prefix = (string) config('manticore.pagination.cache_prefix', 'manticore:pagination:');
        $hash = $this->computeFiltersContextHash();
        
        return $prefix . 'total:' . $hash . ($suffix ? ':' . $suffix : '');
    }

    /**
     * Get the TTL for pagination total cache.
     */
    protected function getPaginationTotalCacheTtl(): int
    {
        return (int) config('manticore.pagination.total_cache_ttl', 300);
    }

    protected function usesSqlQueryMode(): bool
    {
        return !empty($this->groupBy) || !empty($this->having) || !empty($this->select);
    }

    /**
     * Get the total number of matches for the current query filters.
     * This executes a separate query to get the ACTUAL total, not limited by pagination.
     *
     * @return int
     */
    protected function getTotalMatches(): int
    {
        $cacheKey = $this->getPaginationCacheKey();
        $cachedTotal = Cache::get($cacheKey);

        if (is_numeric($cachedTotal)) {
            return (int) $cachedTotal;
        }

        try {
            $groupField = $this->groupBy[0] ?? null;

            if ($groupField) {
                $groupField = strtolower($groupField);
                $countBuilder = clone $this;
                $countBuilder->limit = null;
                $countBuilder->offset = null;
                $countBuilder->sort = [];
                $countBuilder->groupBy = [];
                $countBuilder->having = [];
                $countBuilder->highlight = false;
                $countBuilder->scriptFields = [];

                $countBuilder->select = ["COUNT(DISTINCT `{$groupField}`) as cc"];
                $countBuilder->option('max_matches', 1000);
                $countBuilder->option('distinct_precision_threshold', 0);

                $sql = $countBuilder->buildSqlQuery();
                $resultSet = $countBuilder->executeSqlQuery($sql, true);
                $rows = $countBuilder->extractRawRows($resultSet);

                $total = (int) ($rows[0]['cc'] ?? 0);
            } else {
                $countBuilder = clone $this;
                $countBuilder->limit = 1;
                $countBuilder->offset = 0;
                $countBuilder->sort = [];
                $countBuilder->highlight = false;
                $countBuilder->scriptFields = [];
                $countBuilder->option('max_matches', 1000);
                $countBuilder->option('distinct_precision_threshold', 0);

                $resultSet = $countBuilder->executeSqlQuery($countBuilder->buildSqlQuery(), true);
                $total = $countBuilder->extractTotalFromResultSet($resultSet, 0);
            }

            if ($total > 0) {
                Cache::put($cacheKey, $total, now()->addSeconds($this->getPaginationTotalCacheTtl()));
            }

            return $total;

        } catch (\Throwable $e) {
            Log::warning('Failed to get total matches for pagination', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Flush the pagination total cache for all contexts.
     */
    public function flushPaginationTotalCache(): static
    {
        Cache::forget($this->getPaginationCacheKey());
        return $this;
    }

    protected function canUseOptimizedConsolidatedPagination(): bool
    {
        if ($this->rawQuery || !empty($this->groupBy) || !empty($this->having) || !empty($this->select)) {
            return false;
        }

        $method = new \ReflectionMethod($this, 'getRawRowsForCurrentQuery');

        return $method->getDeclaringClass()->getName() === self::class;
    }

    protected function canUseSqlGroupedConsolidatedPagination(string $groupField): bool
    {
        if ($this->rawQuery || !$this->usesSqlQueryMode()) {
            return false;
        }

        if (count($this->groupBy) !== 1) {
            return false;
        }

        return strcasecmp((string) $this->groupBy[0], $groupField) === 0;
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
        $offset = max(0, ($page - 1) * $perPage);
        $groupedBuilder = clone $this;

        $groupedBuilder->groupBy([$groupField]);
        $groupedBuilder->select([$groupField]);
        $groupedBuilder->limit($perPage)->offset($offset);

        if (!array_key_exists('max_matches', $groupedBuilder->option)) {
            $groupedBuilder->option('max_matches', 1000000);
        }

        $resultSet = $groupedBuilder->executeSqlQuery($groupedBuilder->buildSqlQuery(), true);
        $rows = $groupedBuilder->extractRawRows($resultSet);
        $total = $groupedBuilder->extractTotalFromResultSet($resultSet, count($rows));

        return ['rows' => $rows, 'total' => $total];
    }

    protected function fetchConsolidatedHistoryRows(string $groupField, array $groupValues): array
    {
        if (empty($groupValues)) {
            return [];
        }

        $historyBuilder = clone $this;
        $unlimitedMaxMatches = (int) config('manticore.unlimited_max_matches', 1000000);

        $historyBuilder->limit = $unlimitedMaxMatches;
        $historyBuilder->offset = 0;
        $historyBuilder->sort = [];
        $historyBuilder->groupBy = [];
        $historyBuilder->having = [];
        $historyBuilder->select = [];
        $historyBuilder->whereIn($groupField, $groupValues);

        if (!array_key_exists('max_matches', $historyBuilder->option)) {
            $historyBuilder->option('max_matches', $unlimitedMaxMatches);
        }

        $method = new \ReflectionMethod($historyBuilder, 'getRawRowsForCurrentQuery');

        if ($method->getDeclaringClass()->getName() !== self::class) {
            return $historyBuilder->getRawRowsForCurrentQuery();
        }

        $resultSet = $historyBuilder->executeSqlQuery($historyBuilder->buildSqlQuery(), true);

        return $historyBuilder->extractRawRows($resultSet);
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
        $groupedPage = $this->fetchConsolidatedPageKeyRows($groupField, $perPage, $page);
        $pageGroupRows = $groupedPage['rows'];
        $total = $groupedPage['total'];

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

        $historyRows = $this->fetchConsolidatedHistoryRows($groupField, $orderedGroupValues);

        $consolidatedRows = $this->consolidateRawRows(
            $historyRows,
            $groupField,
            $historyAttribute,
            $preserveGroupFieldInHistory
        );

        return [
            'rows' => $this->reorderConsolidatedRowsByGroupField($consolidatedRows, $groupField, $orderedGroupValues),
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

        $offset = max(0, ($page - 1) * $perPage);
        $builder = clone $this;

        $builder->limit($perPage)->offset($offset);

        $resultSet = $builder->executeSqlQuery($builder->buildSqlQuery(), true);
        $rows = $builder->extractRawRows($resultSet);
        $total = $this->getTotalMatches() ?? $builder->extractTotalFromResultSet($resultSet, count($rows));

        $orderedGroupValues = [];
        foreach ($rows as $row) {
            $groupValue = $this->resolveRowFieldValue($row, $groupField);

            if ($groupValue !== null) {
                $orderedGroupValues[] = $groupValue;
            }
        }

        $historyRows = $this->fetchConsolidatedHistoryRows($groupField, $orderedGroupValues);

        $pageConsolidatedRows = $this->consolidateRawRows(
            $historyRows,
            $groupField,
            $historyAttribute,
            $preserveGroupFieldInHistory
        );

        return [
            'rows' => $this->reorderConsolidatedRowsByGroupField($pageConsolidatedRows, $groupField, $orderedGroupValues),
            'total' => $total,
        ];
    }

    protected function paginateConsolidatedSqlGrouped(
        string $groupField,
        int $perPage,
        int $page,
        string $historyAttribute,
        bool $preserveGroupFieldInHistory
    ): array {
        $offset = max(0, ($page - 1) * $perPage);
        $builder = clone $this;

        $builder->limit($perPage)->offset($offset);

        $resultSet = $builder->executeSqlQuery($builder->buildSqlQuery(), true);
        $rows = $builder->extractRawRows($resultSet);
        $total = $this->getTotalMatches() ?? $builder->extractTotalFromResultSet($resultSet, count($rows));

        if (empty($rows)) {
            return ['rows' => [], 'total' => $total];
        }

        return [
            'rows' => $builder->consolidateRawRows(
                $rows,
                $groupField,
                $historyAttribute,
                $preserveGroupFieldInHistory
            ),
            'total' => $total,
        ];
    }

    protected function canReuseSourceTotalForConsolidatedFallback(string $groupField): bool
    {
        if (empty($this->groupBy) || count($this->groupBy) !== 1) {
            return false;
        }

        return strcasecmp((string) $this->groupBy[0], $groupField) === 0;
    }

    protected function getRawRowsForConsolidatedFallback(string $groupField): array
    {
        $previousLimit = $this->limit;
        $previousOffset = $this->offset;
        $hadMaxMatches = array_key_exists('max_matches', $this->option);
        $previousMaxMatches = $this->option['max_matches'] ?? null;
        $hadAccurateAggregation = array_key_exists('accurate_aggregation', $this->option);
        $previousAccurateAggregation = $this->option['accurate_aggregation'] ?? null;

        try {
            $unlimitedMaxMatches = (int) config('manticore.unlimited_max_matches', 1000000);

            $this->offset = 0;
            $this->limit = $unlimitedMaxMatches;
            $this->option('max_matches', $unlimitedMaxMatches);

            if ($this->usesSqlQueryMode()) {
                $resultSet = $this->executeSqlQuery($this->buildSqlQuery(), true);
                $rows = $this->extractRawRows($resultSet);

                if ($this->canReuseSourceTotalForConsolidatedFallback($groupField)) {
                    return [
                        'rows' => $rows,
                        'total' => $this->getTotalMatches() ?? $this->extractTotalFromResultSet($resultSet, count($rows)),
                    ];
                }

                return ['rows' => $rows, 'total' => null];
            }

            return ['rows' => $this->getRawRowsForCurrentQuery(), 'total' => null];
        } finally {
            $this->limit = $previousLimit;
            $this->offset = $previousOffset;

            if ($hadMaxMatches) {
                $this->option['max_matches'] = $previousMaxMatches;
            } else {
                unset($this->option['max_matches']);
            }

            if ($hadAccurateAggregation) {
                $this->option['accurate_aggregation'] = $previousAccurateAggregation;
            } else {
                unset($this->option['accurate_aggregation']);
            }
        }
    }

    protected function extractTotalFromResultSet(mixed $resultSet, int $fallback = 0): int
    {
        if (!is_object($resultSet) || !method_exists($resultSet, 'getTotal')) {
            return $fallback;
        }

        $total = $resultSet->getTotal();

        if (is_numeric($total)) {
            return (int) $total;
        }

        if (is_array($total) && isset($total['value']) && is_numeric($total['value'])) {
            return (int) $total['value'];
        }

        return $fallback;
    }

    protected function removePageKeysFromQuery(array $query, string $pageName): array
    {
        $normalizedPageName = strtolower($pageName);

        foreach (array_keys($query) as $key) {
            $normalizedKey = strtolower((string) $key);

            if ($normalizedKey === $normalizedPageName || $normalizedKey === 'page') {
                unset($query[$key]);
            }
        }

        return $query;
    }

    protected function resolvePageFromRequestInput(string $pageName, ?int $page): int
    {
        if (filled($page) && $page > 0) {
            return $page;
        }

        if (app()->bound('request')) {
            $request = app('request');

            if ($request instanceof Request) {
                $input = $request->input();
                $normalizedPageName = strtolower($pageName);

                foreach ($input as $key => $value) {
                    $normalizedKey = strtolower((string) $key);

                    if ($normalizedKey !== $normalizedPageName && $normalizedKey !== 'page') {
                        continue;
                    }

                    if (is_numeric($value) && (int) $value > 0) {
                        return (int) $value;
                    }
                }
            }
        }

        $resolvedPage = LengthAwarePaginator::resolveCurrentPage($pageName);

        return $resolvedPage > 0 ? $resolvedPage : 1;
    }

    protected function paginationContextKeyName(): string
    {
        return (string) config('manticore.pagination.context_key', '_mctx');
    }

    protected function paginationContextPrefix(): string
    {
        return (string) config('manticore.pagination.cache_prefix', 'manticore:pagination:');
    }

    protected function paginationContextTtlSeconds(): int
    {
        return (int) config('manticore.pagination.context_ttl', 900);
    }

    protected function maxPaginationQueryLength(): int
    {
        return (int) config('manticore.pagination.max_query_length', 1500);
    }

    protected function paginationContextCacheKey(string $contextId): string
    {
        return $this->paginationContextPrefix().$contextId;
    }

    protected function loadPaginationContext(string $contextId): array
    {
        $cached = Cache::get($this->paginationContextCacheKey($contextId));

        return is_array($cached) ? $cached : [];
    }

    protected function storePaginationContext(array $filters): string
    {
        $contextId = Str::random(40);

        Cache::put(
            $this->paginationContextCacheKey($contextId),
            $filters,
            now()->addSeconds($this->paginationContextTtlSeconds())
        );

        return $contextId;
    }

    protected function shouldUsePaginationContext(array $filters): bool
    {
        return strlen(http_build_query($filters)) > $this->maxPaginationQueryLength();
    }

    protected function resolvePaginationInput(string $pageName, Request $request): array
    {
        $input = $request->input();
        $contextKey = $this->paginationContextKeyName();

        $contextId = $input[$contextKey] ?? null;
        if (is_string($contextId) && $contextId !== '') {
            $cached = $this->loadPaginationContext($contextId);

            if (!empty($cached)) {
                $input = array_replace_recursive($cached, $input);
            }
        }

        $input = $this->removePageKeysFromQuery($input, $pageName);

        return $input;
    }

    public static function resolvePaginationInputFromRequest(string $pageName = 'page', ?Request $request = null): array
    {
        $request = $request ?? (app()->bound('request') ? app('request') : null);

        if (!$request instanceof Request) {
            return [];
        }

        $contextKey = (string) config('manticore.pagination.context_key', '_mctx');
        $cachePrefix = (string) config('manticore.pagination.cache_prefix', 'manticore:pagination:');
        $input = $request->input();

        $contextId = $input[$contextKey] ?? null;
        if (is_string($contextId) && $contextId !== '') {
            $cached = Cache::get($cachePrefix.$contextId);

            if (is_array($cached) && !empty($cached)) {
                $input = array_replace_recursive($cached, $input);
            }
        }

        return $input;
    }

    protected function resolvePaginatorOptions(string $pageName): array
    {
        $options = [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ];

        if (!app()->bound('request')) {
            return $options;
        }

        $request = app('request');

        if (!$request instanceof Request) {
            return $options;
        }

        $query = $this->resolvePaginationInput($pageName, $request);

        $contextKey = $this->paginationContextKeyName();

        if ($this->shouldUsePaginationContext($query)) {
            $contextId = $this->storePaginationContext($query);
            $query = [$contextKey => $contextId];
        } else {
            unset($query[$contextKey]);
        }

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $options;
    }

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

    public function match(string $keywords, ?string $field = null, string $boolean = 'AND'): static
    {
        $this->match[] = [
            'field' => $field ?: '*',
            'keywords' => $keywords,
            'boolean' => $boolean,
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
        $resultSet = $this->executeSqlQuery($sql, true);
        $rows = $this->extractRawRows($resultSet);
        $models = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    public function get(): Collection
    {
        if($this->rawQuery) {
            return $this->fetchRawQuery();
        }

        if ($this->usesSqlQueryMode()) {
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

    public function getConsolidatedBy(
    string $groupField,
    string $historyAttribute = 'history',
    bool $preserveGroupFieldInHistory = true
    ): SupportCollection {
        return $this->consolidateAllBy(
            $groupField,
            $historyAttribute,
            $preserveGroupFieldInHistory
        );
    }

    public function paginateConsolidatedBy(
        string $groupField,
        int $perPage = 15,
        string $pageName = 'page',
        ?int $page = null,
        string $historyAttribute = 'history',
        bool $preserveGroupFieldInHistory = true
    ): LengthAwarePaginator {
        $page = $this->resolvePageFromRequestInput($pageName, $page);
        $paginatorOptions = $this->resolvePaginatorOptions($pageName);

        $data = $this->canUseOptimizedConsolidatedPagination()
            ? $this->paginateConsolidatedOptimized(
                $groupField,
                $perPage,
                $page,
                $historyAttribute,
                $preserveGroupFieldInHistory
            )
            : $this->paginateConsolidatedFallback(
                $groupField,
                $perPage,
                $page,
                $historyAttribute,
                $preserveGroupFieldInHistory
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

        $models = $this->hydrateModelsFromRows($data['rows']);
        $results = $this->applyEloquentWith($models);

        return new LengthAwarePaginator(
            $results,
            (int) ($data['total'] ?? $results->count()),
            $perPage,
            $page,
            $paginatorOptions
        );
    }

    protected function getRawRowsForCurrentQuery(): array
    {
        if ($this->rawQuery) {
            $results = $this->executeSqlQuery($this->rawQuery, $this->rawQueryMode);

            return $this->extractRawRows($results);
        }

        if ($this->usesSqlQueryMode()) {
            $sql = $this->buildSqlQuery();
            $results = $this->executeSqlQuery($sql, true);

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
        $page = $this->resolvePageFromRequestInput($pageName, $page);
        $paginatorOptions = $this->resolvePaginatorOptions($pageName);
        $offset = max(0, ($page - 1) * $perPage);
        
        if (!array_key_exists('max_matches', $this->option)) {
            $this->option('max_matches', $this->maxMatchesForOffsetWindow($offset, $perPage));
        }
        
        $this->limit($perPage)->offset($offset);

        if ($this->rawQuery) {
            $results = $this->fetchRawQuery();
            $total = $results->count();
        } else {
            $total = $this->getTotalMatches();
        
            if ($this->usesSqlQueryMode()) {
                $resultSet = $this->executeSqlQuery($this->buildSqlQuery(), true);
            } else {
                $resultSet = $this->search()->get();
            }

            $rows = $this->extractRawRows($resultSet);
            $results = $this->applyEloquentWith($this->hydrateModelsFromRows($rows));
            
            if ($total === 0 && $results->count() > 0) {
                Log::warning('getTotalMatches() returned 0, using result count as fallback', [
                    'page' => $page,
                    'perPage' => $perPage,
                    'resultCount' => $results->count(),
                ]);
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
