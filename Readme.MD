# Laravel Manticore Search

A powerful integration of [Manticore Search](https://manticoresearch.com/) with Laravel, offering a fluent query builder and Eloquent-style API for full-text search and filtering.

## Installation

```bash
composer require renatomaldonado/laravel-manticore-search
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --provider="ManticoreLaravel\ManticoreServiceProvider"
```

Then edit `config/manticore.php`:

```php
return [
    'host' => env('MANTICORE_HOST', '127.0.0.1'),
    'port' => env('MANTICORE_PORT', 9306),
    'password' => env('MANTICORE_PASSWORD', null),
    'username' => env('MANTICORE_USERNAME', null),
    'transport' => env('MANTICORE_TRANSPORT', 'http'),
    'timeout' => env('MANTICORE_TIMEOUT', 5),
    'persistent' => env('MANTICORE_PERSISTENT', false),
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

## Testing

The package includes a full PHPUnit test suite. To run it:

```bash
./vendor/bin/phpunit
```

Make sure your Manticore server is running and your test indexes are populated.
