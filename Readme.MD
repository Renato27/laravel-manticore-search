# Laravel Manticore Search

A powerful integration of [Manticore Search](https://manticoresearch.com/) with Laravel, offering a fluent query builder and Eloquent-style API for full-text search and filtering.

## Installation

```bash
composer require zuvinova/manticore-laravel-search
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="ManticoreLaravel\ManticoreServiceProvider"
```

Then edit `config/manticore.php`:

**Single connection (legacy flat format):**

```php
return [
    'host'       => env('MANTICORE_HOST', '127.0.0.1'),
    'port'       => env('MANTICORE_PORT', 9306),
    'username'   => env('MANTICORE_USERNAME', null),
    'password'   => env('MANTICORE_PASSWORD', null),
    'transport'  => env('MANTICORE_TRANSPORT', 'http'),
    'timeout'    => env('MANTICORE_TIMEOUT', 5),
    'persistent' => env('MANTICORE_PERSISTENT', false),
];
```

**Named connections:**

```php
return [
    'default' => 'primary',

    'connections' => [
        'primary' => [
            'host'       => env('MANTICORE_HOST', '127.0.0.1'),
            'port'       => env('MANTICORE_PORT', 9306),
            'transport'  => 'http',
            'timeout'    => 5,
            'persistent' => false,
        ],
        'analytics' => [
            'host' => env('MANTICORE_ANALYTICS_HOST', '10.0.0.2'),
            'port' => env('MANTICORE_ANALYTICS_PORT', 9306),
        ],
    ],
];
```

## Model Setup

Extend your Eloquent model with the `HasManticoreSearch` trait and define the `searchableAs()` method:

```php
use ManticoreLaravel\Traits\HasManticoreSearch;

class Company extends Model
{
    use HasManticoreSearch;

    public function searchableAs(): array
    {
        return ['companies_index_1', 'companies_index_2'];
    }
}
```

## Basic Usage

### Match and Filter
```php
Company::manticore()
    ->match('technology')
    ->where('country', 'US')
    ->limit(10)
    ->get();
```

### Operators
```php
Company::manticore()
    ->where('employees', '>=', 50)
    ->whereBetween('revenue', [10000, 50000])
    ->get();
```

### Pagination
```php
Company::manticore()
    ->match('startup')
    ->paginate(15);
```

Pagination links automatically preserve the current request filters (except the page key),
including both GET query string filters and POST/JSON payload filters. This ensures page 2+
always continues the same filtered search context.

When filter payloads are too large for URL query strings, pagination links automatically switch
to a short context token (`_mctx`) and store the full filter payload in cache.

You can recover the full filter payload in follow-up requests with:

```php
use ManticoreLaravel\Builder\ManticoreBuilder;

$input = ManticoreBuilder::resolvePaginationInputFromRequest();
```

### Aggregations (Facets)
```php
Company::manticore()
    ->aggregate('sectors', [
        'terms' => [
            'field' => 'sector',
            'size' => 5
        ]
    ])
     ->getFacets();
```

### Sorting
```php
Company::manticore()
    ->orderBy('created_at', 'desc')
    ->get();
```

### Grouping
```php
Company::manticore()
    ->groupBy('country')
    ->get();
```

### Having
```php
Company::manticore()
    ->select(['country', 'COUNT(*) as total'])
    ->groupBy('country')
    ->having("COUNT(*) > 1")
    ->get();
```

### Geo Distance
```php
Company::manticore()
    ->whereGeoDistance('location', 40.4168, -3.7038, 10000)
    ->get();
```

### Raw SQL
```php
Company::manticore()
    ->rawQuery("SELECT * FROM companies_index WHERE country = 'US'")
    ->get();
```

### Convert to SQL
```php
Company::manticore()
    ->match('ai')
    ->where('country', 'UK')
    ->toSql();
```

### Named Connections
```php
Company::manticore()
    ->usingConnection('analytics')
    ->match('startup')
    ->limit(10)
    ->get();
```

### Optional Facade
```php
use ManticoreLaravel\Facades\Manticore;

$config = Manticore::resolveConfig('default');

$client = Manticore::client('default');

$table = Manticore::table('companies_index', 'default');
```

The facade is intentionally thin and delegates to the package manager. Model-based
queries via `Model::manticore()` remain the primary high-level entry point.

## Testing

The package includes two test suites:

- **`tests/Feature/`** — Pest tests. All mocked; no live Manticore server required.
- **`tests/ManticoreConnectionResolverTest.php`** — PHPUnit unit tests. Mocked; no server required.
- **`tests/ManticoreBuilderTest.php`** — PHPUnit integration tests. Requires a live Manticore instance with a `test` index.

**Run Pest feature tests (no server needed):**

```bash
./vendor/bin/pest tests/Feature
```

**Run PHPUnit unit tests (no server needed):**

```bash
./vendor/bin/phpunit tests/ManticoreConnectionResolverTest.php
```

**Run PHPUnit integration tests (requires live Manticore):**

```bash
./vendor/bin/phpunit tests/ManticoreBuilderTest.php
```
