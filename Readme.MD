# Laravel Manticore Search

A driver for integrating **Manticore Search** with Laravel, featuring a fluent query builder, Eloquent model hydration, robust pagination, and support for multiple named connections.

## What this library does

- Exposes a fluent query builder through `Model::manticore()`.
- Supports full-text search (`match`) plus structured filters (`where`, `whereIn`, `whereBetween`, `whereNull`, `whereRaw`, etc.).
- Supports SQL mode (`select`, `groupBy`, `having`, `orderBy`, `toSql`, `rawQuery`).
- Hydrates results into your model instances, including cast resolution and custom attribute mapping.
- Preserves request filters during pagination, including large payloads via a cached context token.
- Provides an optional `Manticore` facade for direct manager/client/table access.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1` |
| Laravel | `^10.0 \| ^11.0 \| ^12.0` |
| `manticoresoftware/manticoresearch-php` | `^4.0` |

## Installation

```bash
composer require renatomaldonado/manticore-laravel-search
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="ManticoreLaravel\ManticoreServiceProvider"
```

### Named connections (recommended)

```php
// config/manticore.php
return [
    'default' => env('MANTICORE_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'host'          => env('MANTICORE_HOST', '127.0.0.1'),
            'port'          => env('MANTICORE_PORT', 9308),
            'username'      => env('MANTICORE_USERNAME', null),
            'password'      => env('MANTICORE_PASSWORD', null),
            'transport'     => env('MANTICORE_TRANSPORT', 'Http'),
            'timeout'       => env('MANTICORE_TIMEOUT', 5),
            'persistent'    => env('MANTICORE_PERSISTENT', false),
            'max_matches'   => env('MANTICORE_MAX_MATCHES', 1000),
            'limit_results' => env('MANTICORE_LIMIT_RESULTS', 0), // 0 = disabled
        ],

        'analytics' => [
            'host'        => env('MANTICORE_ANALYTICS_HOST', '10.0.0.2'),
            'port'        => env('MANTICORE_ANALYTICS_PORT', 9308),
            'max_matches' => 2000,
        ],
    ],

    // Used internally when fetching all rows for consolidation.
    'unlimited_max_matches' => env('MANTICORE_UNLIMITED_MAX_MATCHES', 1000000),

    'pagination' => [
        'context_key'      => env('MANTICORE_PAGINATION_CONTEXT_KEY',     '_mctx'),
        'max_query_length' => env('MANTICORE_PAGINATION_MAX_QUERY_LENGTH', 1500),
        'context_ttl'      => env('MANTICORE_PAGINATION_CONTEXT_TTL',      900),
        'cache_prefix'     => env('MANTICORE_PAGINATION_CACHE_PREFIX',     'manticore:pagination:'),
        'total_cache_ttl'  => env('MANTICORE_PAGINATION_TOTAL_CACHE_TTL',  300),
    ],
];
```

### Legacy flat config (backward compatibility)

If `connections` is empty or missing, the resolver falls back to the flat top-level keys (`manticore.host`, `manticore.port`, etc.) that predate multi-connection support.

## Model setup

Add the `HasManticoreSearch` trait and implement `searchableAs()`.

```php
use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\Traits\HasManticoreSearch;

class Company extends Model
{
    use HasManticoreSearch;

    protected $fillable = ['EntityID', 'EntityName', 'CountryISO'];

    public function searchableAs(): string
    {
        return 'companies_index';
    }
}
```

`searchableAs()` may return a `string` or an `array` of index names for multi-index queries.

## Quick example

```php
$results = Company::manticore()
    ->match('startup')
    ->where('countryiso', 'BR')
    ->orderBy('entityid', 'desc')
    ->limit(10)
    ->get();
```

## Builder API

### Filtering

| Method | Description |
|---|---|
| `match(string $keywords, ?string $field, string $boolean)` | Full-text search. `$field` defaults to `*`. |
| `where(string $field, mixed $op, mixed $value)` | Equality or comparison. Supports `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`. |
| `orWhere(string $field, mixed $op, mixed $value)` | OR variant of `where`. |
| `whereNot(string $field, mixed $op, mixed $value)` | Negated filter. |
| `whereIn(string $field, array $values)` | IN filter. |
| `whereNotIn(string $field, array $values)` | NOT IN filter. |
| `whereBetween(string $field, array $range)` | Range filter `[min, max]`. |
| `whereNull(string $field)` | IS NULL — SQL mode only. |
| `whereNotNull(string $field)` | IS NOT NULL — SQL mode only. |
| `whereRaw(string $sql, string $boolean)` | Inject a raw SQL fragment — SQL mode only. |
| `whereGeoDistance(string $field, float $lat, float $lon, float $meters)` | Geo-distance filter. |

### Ordering, limiting, selecting

| Method | Description |
|---|---|
| `orderBy(string\|array $column, ?string $direction)` | Accepts a column + direction, or an `[col => dir]` array. |
| `limit(int $limit)` | |
| `offset(int $offset)` | |
| `forPage(int $page, int $perPage)` | Sets `limit` + `offset` for the given page number. |
| `select(array\|string $fields)` | Triggers SQL mode. |
| `groupBy(array\|string $fields)` | Triggers SQL mode. |
| `having(array\|string $conditions)` | Triggers SQL mode. |

### Options and metadata

| Method | Description |
|---|---|
| `option(string $key, mixed $value)` | Set a Manticore query option (`max_matches`, etc.). |
| `maxMatches(int $value)` | Shorthand for `option('max_matches', $value)`. |
| `aggregate(string $name, array $aggregation)` | Add a facet aggregation. |
| `expression(string $name, mixed $exp)` | Add a script/expression field. |
| `withHighlight()` | Enable highlight in Search API responses. |

### Eager loading

```php
// Simple relation
->with('owner')

// Column-constrained
->with('owner:id,name')

// Closure
->with(['tags' => fn($q) => $q->where('active', true)])
```

### Connection and index overrides

| Method | Description |
|---|---|
| `usingConnection(string $name)` | Use a named connection from config. Flushes the local client cache. |
| `useIndex(string\|array $indexes)` | Override `searchableAs()`. Supports multi-index. |
| `rawQuery(string $sql, bool $rawMode)` | Execute manual SQL, bypassing the builder. |

### Utility chainables

| Method | Description |
|---|---|
| `when(mixed $condition, callable $cb, ?callable $default)` | Apply a callback only when `$condition` is truthy. |
| `tap(callable $callback)` | Inspect/modify the builder mid-chain without breaking it. |
| `dump()` | Dump the compiled SQL and options, then continue the chain. |
| `dd()` | Dump the compiled SQL and options, then exit. |

### Execution

| Method | Returns |
|---|---|
| `get()` | `Illuminate\Database\Eloquent\Collection` |
| `first()` | Model or `null` |
| `last()` | Model or `null` |
| `count()` | `int` |
| `pluck(string $field)` | `Collection` |
| `toArray()` | `array` |
| `toJson(int $options)` | `string` |
| `toSql()` | `string` — compiled SQL, no network call |
| `chunk(int $size, callable $callback)` | Iterates results in pages; returns `false` if callback returns `false`. |
| `paginate(int $perPage, string $pageName, ?int $page)` | `LengthAwarePaginator` |
| `getFacets()` | `array` (not available in `rawQuery` mode) |

## Consolidation (group-merge)

Consolidation fetches multiple documents and merges all rows that share the same `$groupField` value into a single model, storing the originals under a `$historyAttribute`.

```php
// Single consolidated result
$company = Company::manticore()
    ->where('entity_id', 42)
    ->consolidateBy('entity_id');

// All consolidated results
$companies = Company::manticore()
    ->match('fintech')
    ->consolidateAllBy('entity_id');

// Paginated consolidated results
$paginator = Company::manticore()
    ->match('fintech')
    ->paginateConsolidatedBy('entity_id', perPage: 20);
```

`getConsolidatedBy()` is an alias for `consolidateAllBy()` kept for backward compatibility.

## Pagination and filter preservation

`paginate()` and `paginateConsolidatedBy()` automatically carry the current request's filters into the paginator links — including GET params, POST/JSON bodies, and custom page key names.

When the query string exceeds `pagination.max_query_length` bytes, the filters are stored in cache and replaced with a short token (`_mctx`) in the link. The full payload is restored on the next request.

To recover the full input payload manually:

```php
use ManticoreLaravel\Builder\ManticoreBuilder;

$input = ManticoreBuilder::resolvePaginationInputFromRequest('page');
```

To flush the cached total count for a query:

```php
Company::manticore()->where('status', 'active')->flushPaginationTotalCache();
```

Relevant config keys under `pagination`:

| Key | Default | Description |
|---|---|---|
| `context_key` | `_mctx` | URL token name for cached filter context. |
| `max_query_length` | `1500` | Byte threshold above which context caching activates. |
| `context_ttl` | `900` | Seconds to keep the filter context in cache. |
| `cache_prefix` | `manticore:pagination:` | Cache key prefix. |
| `total_cache_ttl` | `300` | Seconds to cache the total count between pages. |

## Optional facade

```php
use ManticoreLaravel\Facades\Manticore;

$config = Manticore::resolveConfig('default');
$client = Manticore::client('default');
$table  = Manticore::table('companies_index', 'default');
$names  = Manticore::connectionNames();

Manticore::forgetClient('default');
```

## Architecture overview

```
src/
├── Builder/
│   ├── Abstracts/ManticoreBuilderAbstract.php  — properties, connection/client wiring, execution primitives
│   ├── Concerns/
│   │   ├── HasQueryConstraints.php             — where/filter methods
│   │   ├── HasSqlCompilation.php               — SQL clause builders (SELECT, WHERE, GROUP BY, …)
│   │   ├── HasResultHydration.php              — row extraction and Eloquent model hydration
│   │   ├── HasEloquentIntegration.php          — eager-loading (with)
│   │   ├── HasConsolidation.php                — group-merge logic
│   │   └── HasPagination.php                   — pagination helpers and context management
│   ├── Grammar/ManticoreGrammar.php            — SQL fragment compiler (WHERE, MATCH, IN, ranges, …)
│   ├── ManticoreBuilder.php                    — public API; composes all concerns
│   └── Utils/
│       ├── Utf8SafeClient.php                  — Client subclass that forces UTF-8 safe JSON decoding
│       ├── Utf8SafeSearch.php                  — Search subclass with UTF-8 safe responses
│       └── Utf8SafeResponse.php                — Response wrapper that re-encodes invalid sequences
├── Contracts/
│   ├── ManticoreBuilderContract.php            — full public interface of ManticoreBuilder
│   └── ConnectionResolverContract.php          — interface for ManticoreConnectionResolver
├── Exceptions/
│   ├── ManticoreConnectionException.php        — connection not found / invalid config
│   ├── ManticoreQueryException.php             — failed SQL query, stores the query string
│   └── ManticoreIndexNotFoundException.php     — index not found (extends ManticoreQueryException)
├── Support/
│   ├── ManticoreManager.php                    — client factory, caches clients by connection name
│   └── ManticoreConnectionResolver.php         — resolves connection config (named or legacy)
├── Traits/HasManticoreSearch.php               — mixed into Eloquent models; provides manticore()
├── Facades/Manticore.php
└── ManticoreServiceProvider.php
```

## Practical examples

### SQL mode

```php
$rows = Company::manticore()
    ->match('fintech')
    ->select(['countryiso', 'COUNT(*) as total'])
    ->groupBy('countryiso')
    ->having('COUNT(*) > 1')
    ->orderBy('total', 'desc')
    ->get();
```

### Raw SQL

```php
$rows = Company::manticore()
    ->rawQuery("SELECT * FROM companies_index WHERE countryiso = 'BR' LIMIT 0, 20")
    ->get();
```

### Facets

```php
$facets = Company::manticore()
    ->aggregate('country_agg', ['terms' => ['field' => 'countryiso', 'size' => 5]])
    ->getFacets();
```

### Chunked processing

```php
Company::manticore()
    ->where('status', 'active')
    ->chunk(200, function ($models) {
        foreach ($models as $model) {
            // process …
        }
    });
```

### Multi-connection

```php
$results = Company::manticore()
    ->usingConnection('analytics')
    ->match('cloud')
    ->get();
```

### Debug

```php
$sql = Company::manticore()
    ->match('fintech')
    ->where('status', 'active')
    ->toSql(); // no network call
```

## Tests

```bash
# Unit and Feature tests — no Manticore server required
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Feature

# Integration tests — requires a running Manticore instance
MANTICORE_HOST=127.0.0.1 MANTICORE_PORT=9308 vendor/bin/pest --testsuite=Integration
```

The CI workflow (`.github/workflows/tests.yml`) runs Unit and Feature tests across PHP 8.1–8.3 and Laravel 10–12, and Integration tests against a live Manticore Search container on PHP 8.2–8.3.
