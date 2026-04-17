<?php

namespace ManticoreLaravel\Builder\Concerns;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provides pagination helpers and context management for the Manticore builder.
 *
 * Properties referenced here are declared in ManticoreBuilderAbstract.
 */
trait HasPagination
{
    protected function configuredMaxMatches(): int
    {
        $value = (int) ($this->resolveConnectionConfig()['max_matches'] ?? 1000);

        return $value > 0 ? $value : 1000;
    }

    protected function maxMatchesForOffsetWindow(int $offset, int $perPage): int
    {
        return max($this->configuredMaxMatches(), $offset + $perPage);
    }

    protected function computeFiltersContextHash(): string
    {
        $signatureBuilder = clone $this;
        $signatureBuilder->limit  = null;
        $signatureBuilder->offset = null;
        $signatureBuilder->sort   = [];

        $signatureOptions = $signatureBuilder->option;
        unset($signatureOptions['max_matches']);

        $signature = [
            'connectionName' => $signatureBuilder->connectionName,
            'index'          => $signatureBuilder->resolveIndexName(),
            'rawQueryMode'   => $signatureBuilder->rawQueryMode,
            'rawQuery'       => $signatureBuilder->rawQuery,
            'sql'            => $signatureBuilder->rawQuery ? null : $signatureBuilder->buildSqlQuery(),
            'option'         => $signatureOptions,
        ];

        return md5(serialize($signature));
    }

    protected function getPaginationCacheKey(string $suffix = ''): string
    {
        $prefix = (string) config('manticore.pagination.cache_prefix', 'manticore:pagination:');
        $hash   = $this->computeFiltersContextHash();

        return $prefix . 'total:' . $hash . ($suffix ? ':' . $suffix : '');
    }

    protected function getPaginationTotalCacheTtl(): int
    {
        return (int) config('manticore.pagination.total_cache_ttl', 300);
    }

    /**
     * Execute a count query to get the real total number of matching documents.
     * Result is cached to avoid re-running on each page load.
     */
    protected function getTotalMatches(): int
    {
        $cacheKey     = $this->getPaginationCacheKey();
        $cachedTotal  = Cache::get($cacheKey);

        if (is_numeric($cachedTotal)) {
            return (int) $cachedTotal;
        }

        try {
            $groupField = $this->groupBy[0] ?? null;

            if ($groupField) {
                $groupFieldSafe = strtolower($groupField);
                $countBuilder   = clone $this;

                $countBuilder->limit        = null;
                $countBuilder->offset       = null;
                $countBuilder->sort         = [];
                $countBuilder->groupBy      = [];
                $countBuilder->having       = [];
                $countBuilder->highlight    = false;
                $countBuilder->scriptFields = [];
                $countBuilder->select       = ["COUNT(DISTINCT `{$groupFieldSafe}`) as cc"];
                $countBuilder->option('max_matches', 1000);
                $countBuilder->option('distinct_precision_threshold', 0);

                $sql       = $countBuilder->buildSqlQuery();
                $resultSet = $countBuilder->executeSqlQuery($sql);
                $rows      = $countBuilder->extractRawRows($resultSet);
                $total     = (int) ($rows[0]['cc'] ?? 0);
            } else {
                $countBuilder = clone $this;

                $countBuilder->limit        = 1;
                $countBuilder->offset       = 0;
                $countBuilder->sort         = [];
                $countBuilder->highlight    = false;
                $countBuilder->scriptFields = [];
                $countBuilder->option('max_matches', 1000);
                $countBuilder->option('distinct_precision_threshold', 0);

                $resultSet = $countBuilder->executeSqlQuery($countBuilder->buildSqlQuery());
                $total     = $countBuilder->extractTotalFromResultSet($resultSet, 0);
            }

            Cache::put($cacheKey, $total, now()->addSeconds($this->getPaginationTotalCacheTtl()));

            return $total;
        } catch (\Throwable $e) {
            Log::warning('ManticoreSearch: Failed to get total matches for pagination', [
                'error' => $e->getMessage(),
            ]);

            return 0;
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

    protected function resolvePageFromRequestInput(string $pageName, ?int $page): int
    {
        if (filled($page) && $page > 0) {
            return $page;
        }

        if (app()->bound('request')) {
            $request = app('request');

            if ($request instanceof Request) {
                $input              = $request->input();
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
        return $this->paginationContextPrefix() . $contextId;
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

    protected function resolvePaginationInput(string $pageName, Request $request): array
    {
        $input      = $request->input();
        $contextKey = $this->paginationContextKeyName();
        $contextId  = $input[$contextKey] ?? null;

        if (is_string($contextId) && $contextId !== '') {
            $cached = $this->loadPaginationContext($contextId);

            if (!empty($cached)) {
                $input = array_replace_recursive($cached, $input);
            }
        }

        return $this->removePageKeysFromQuery($input, $pageName);
    }

    protected function resolvePaginatorOptions(string $pageName): array
    {
        $options = [
            'path'     => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ];

        if (!app()->bound('request')) {
            return $options;
        }

        $request = app('request');

        if (!$request instanceof Request) {
            return $options;
        }

        $query      = $this->resolvePaginationInput($pageName, $request);
        $contextKey = $this->paginationContextKeyName();

        if ($this->shouldUsePaginationContext($query)) {
            $contextId = $this->storePaginationContext($query);
            $query     = [$contextKey => $contextId];
        } else {
            unset($query[$contextKey]);
        }

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $options;
    }
}
