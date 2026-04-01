# Laravel Manticore Search

A driver for integrating **Manticore Search** with Laravel, featuring a fluent API, Eloquent model hydration, robust pagination, and support for multiple connections.

## What this library does

- Exposes a fluent query builder through `Model::manticore()`.
- Supports full-text search (`match`) plus filters (`where`, `whereIn`, `whereBetween`, etc.).
- Supports SQL mode (`select`, `groupBy`, `having`, `orderBy`, `toSql`, `rawQuery`).
- Hydrates results into your Model instances (including casting and attribute mapping).
- Preserves request filters during pagination, including large payloads via a cached context token.
- Provides an optional facade (`Manticore::...`) for manager/client/table access.

## Requirements

- PHP `^8.0`
- Laravel `^10|^11|^12|^13`
- `manticoresoftware/manticoresearch-php ^4.0`

## Installation

```bash
composer require renatomaldonado/manticore-laravel-search
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="ManticoreLaravel\ManticoreServiceProvider"
```

### Recommended structure (multiple connections)

```php
return [
    'default' => env('MANTICORE_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'host' => env('MANTICORE_HOST', '127.0.0.1'),
            'port' => env('MANTICORE_PORT', 9312),
            'username' => env('MANTICORE_USERNAME', null),
            'password' => env('MANTICORE_PASSWORD', null),
            'transport' => env('MANTICORE_TRANSPORT', 'Http'),
            'timeout' => env('MANTICORE_TIMEOUT', 5),
            'persistent' => env('MANTICORE_PERSISTENT', false),
            'max_matches' => env('MANTICORE_MAX_MATCHES', 1000),
        ],

        'analytics' => [
            'host' => env('MANTICORE_ANALYTICS_HOST', '10.0.0.2'),
            'port' => env('MANTICORE_ANALYTICS_PORT', 9312),
            'max_matches' => 2000,
        ],
    ],
];
```

### Legacy compatibility

The library keeps a fallback for the “flat” configuration format (`manticore.host`, `manticore.port`, etc.).
If `connections` is empty or missing, the resolver uses this legacy format.

## Model usage

Use the `HasManticoreSearch` trait and implement `searchableAs()`.

```php
use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\Traits\HasManticoreSearch;

class Company extends Model
{
    use HasManticoreSearch;

    protected $fillable = [
        'EntityID',
        'EntityName',
        'CountryISO',
    ];

    public function searchableAs(): array
    {
        return ['companies_index'];
    }
}
```

> If the model uses the trait and does not implement `searchableAs()`, a `RuntimeException` is thrown.

## Quick example

```php
$results = Company::manticore()
    ->match('startup')
    ->where('countryiso', 'BR')
    ->orderBy('entityid', 'desc')
    ->limit(10)
    ->get();
```

## Builder public API

### Query building

- `match(string $keywords, ?string $field = null, string $boolean = 'AND')`
- `where(string $field, mixed $operatorOrValue, mixed $value = null)`
- `orWhere(string $field, mixed $operatorOrValue, mixed $value = null)`
- `whereNot(string $field, mixed $operatorOrValue, mixed $value = null)`
- `whereIn(string $field, array $values)`
- `whereNotIn(string $field, array $values)`
- `whereBetween(string $field, array $range)`
- `whereGeoDistance(string $field, float $lat, float $lon, float $distanceMeters)`
- `orderBy(string|array $column, ?string $direction = null)`
- `select(array|string $fields)`
- `groupBy(array|string $fields)`
- `having(array|string $conditions)`
- `aggregate(string $name, array $aggregation)`
- `expression(string $name, mixed $exp)`
- `option(string $key, mixed $value)`
- `maxMatches(int $value)`
- `withHighlight()`
- `with(array|string ...$relations)`
- `when($condition, callable $callback, ?callable $default = null)`

### Execution context

- `usingConnection(string $name)` → selects a named connection.
- `useIndex(array|string $indexes)` → overrides the index(es) returned by `searchableAs()`.
- `rawQuery(string $raw, bool $rawMode = false)` → executes manual SQL.
- `toSql()` → inspects the generated SQL.

### Execution and return values

- `get()`
- `first()`
- `last()`
- `count()`
- `pluck(string $field)`
- `toArray()`
- `toJson(int $options = 0)`
- `paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null)`
- `getFacets()` (not supported in `rawQuery`)

### Consolidation (deduplication by key)

- `consolidateBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true)`
  - Returns **one** consolidated item.
- `consolidateAllBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true)`
  - Returns a consolidated **collection**.
- `getConsolidatedBy(...)`
  - Alias for `consolidateAllBy`.
- `paginateConsolidatedBy(string $groupField, int $perPage = 15, string $pageName = 'page', ?int $page = null, ...)`
  - Paginates results that have already been consolidated.

## Pagination and filter preservation

`paginate()` and `paginateConsolidatedBy()` automatically preserve filters from the current request:

- GET query params
- POST/JSON payloads
- custom page names (`pageName`)
- case variations in the page key (`Page`, `page`)

When the query string becomes too large, the library stores the filters in cache and uses a short token (`_mctx`) in the link.

To recover the full payload in subsequent requests:

```php
use ManticoreLaravel\Builder\ManticoreBuilder;

$input = ManticoreBuilder::resolvePaginationInputFromRequest('page');
```

Relevant config (`config/manticore.php`):

- `pagination.context_key`
- `pagination.max_query_length`
- `pagination.context_ttl`
- `pagination.cache_prefix`
- `pagination.total_cache_ttl`

## Optional facade

```php
use ManticoreLaravel\Facades\Manticore;

$config = Manticore::resolveConfig('default');
$client = Manticore::client('default');
$table  = Manticore::table('companies_index', 'default');
$names  = Manticore::connectionNames();

Manticore::forgetClient('default');
```

## Practical examples

### SQL mode (select + group + having)

```php
$rows = Company::manticore()
    ->match('fintech')
    ->select(['countryiso', 'COUNT(*) as total'])
    ->groupBy('countryiso')
    ->having('COUNT(*) > 1')
    ->orderBy('total', 'desc')
    ->limit(10)
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
    ->aggregate('countryiso_agg', [
        'terms' => [
            'field' => 'countryiso',
            'size' => 5,
        ],
    ])
    ->getFacets();
```

### Eager loading after hydration

```php
$rows = Company::manticore()
    ->match('cloud')
    ->with('owner:id,name', 'tags')
    ->limit(20)
    ->get();
```

## Important notes

- `max_matches` is always included in the SQL `OPTION` clause (connection value or method override).
- `usingConnection()` invalidates the builder's local client/config cache to avoid incorrect state.
- `useIndex()` invalidates the builder's resolved index cache.
- The library includes “UTF-8 safe” clients/responses to handle responses with invalid encoding.

## Tests

Run feature tests (without a Manticore server):

```bash
./vendor/bin/pest tests/Feature
```

Run the resolver unit test (without a server):

```bash
./vendor/bin/phpunit tests/ManticoreConnectionResolverTest.php
```

Run builder integration tests (requires Manticore with a test index):

```bash
./vendor/bin/phpunit tests/ManticoreBuilderTest.php
```
