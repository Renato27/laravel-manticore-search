# Laravel Manticore Search

Driver para integrar **Manticore Search** ao Laravel com API fluente, hidratação em modelos Eloquent, paginação robusta e suporte a múltiplas conexões.

## O que esta library faz

- Expõe um query builder fluente via `Model::manticore()`.
- Permite busca full-text (`match`) + filtros (`where`, `whereIn`, `whereBetween`, etc.).
- Suporta modo SQL (`select`, `groupBy`, `having`, `orderBy`, `toSql`, `rawQuery`).
- Hidrata resultados em instâncias do seu Model (com cast e mapeamento de atributos).
- Preserva filtros da requisição na paginação, inclusive payloads grandes via token de contexto em cache.
- Oferece fachada opcional (`Manticore::...`) para acesso ao manager/cliente/tabela.

## Requisitos

- PHP `^8.0`
- Laravel `^10|^11|^12|^13`
- `manticoresoftware/manticoresearch-php ^4.0`

## Instalação

```bash
composer require renatomaldonado/manticore-laravel-search
```

## Configuração

Publique o arquivo de configuração:

```bash
php artisan vendor:publish --provider="ManticoreLaravel\ManticoreServiceProvider"
```

### Estrutura recomendada (múltiplas conexões)

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

### Compatibilidade legada

A library mantém fallback para configuração “flat” (`manticore.host`, `manticore.port`, etc.).
Se `connections` estiver vazio/ausente, o resolver usa esse formato legado.

## Uso no Model

Use a trait `HasManticoreSearch` e implemente `searchableAs()`.

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

> Se o model usar a trait e não implementar `searchableAs()`, uma `RuntimeException` é lançada.

## Exemplo rápido

```php
$results = Company::manticore()
    ->match('startup')
    ->where('countryiso', 'BR')
    ->orderBy('entityid', 'desc')
    ->limit(10)
    ->get();
```

## API pública do Builder

### Construção de consulta

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

### Contexto de execução

- `usingConnection(string $name)` → escolhe conexão nomeada.
- `useIndex(array|string $indexes)` → sobrescreve índice(s) do `searchableAs()`.
- `rawQuery(string $raw, bool $rawMode = false)` → executa SQL manual.
- `toSql()` → inspeciona SQL gerado.

### Execução e retorno

- `get()`
- `first()`
- `last()`
- `count()`
- `pluck(string $field)`
- `toArray()`
- `toJson(int $options = 0)`
- `paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null)`
- `getFacets()` (não suportado em `rawQuery`)

### Consolidação (deduplicação por chave)

- `consolidateBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true)`
  - Retorna **um** item consolidado.
- `consolidateAllBy(string $groupField, string $historyAttribute = 'history', bool $preserveGroupFieldInHistory = true)`
  - Retorna **coleção** consolidada.
- `getConsolidatedBy(...)`
  - Alias de `consolidateAllBy`.
- `paginateConsolidatedBy(string $groupField, int $perPage = 15, string $pageName = 'page', ?int $page = null, ...)`
  - Pagina resultados já consolidados.

## Paginação e preservação de filtros

`paginate()` e `paginateConsolidatedBy()` preservam automaticamente os filtros da requisição atual:

- GET query params
- payloads POST/JSON
- nomes de página customizados (`pageName`)
- variações de caixa em chave de página (`Page`, `page`)

Quando o query string fica grande, a library salva os filtros em cache e usa token curto (`_mctx`) no link.

Para recuperar o payload completo em requisições seguintes:

```php
use ManticoreLaravel\Builder\ManticoreBuilder;

$input = ManticoreBuilder::resolvePaginationInputFromRequest('page');
```

Config relevante (`config/manticore.php`):

- `pagination.context_key`
- `pagination.max_query_length`
- `pagination.context_ttl`
- `pagination.cache_prefix`
- `pagination.total_cache_ttl`

## Facade opcional

```php
use ManticoreLaravel\Facades\Manticore;

$config = Manticore::resolveConfig('default');
$client = Manticore::client('default');
$table  = Manticore::table('companies_index', 'default');
$names  = Manticore::connectionNames();

Manticore::forgetClient('default');
```

## Exemplos práticos

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

### Eager load após hidratação

```php
$rows = Company::manticore()
    ->match('cloud')
    ->with('owner:id,name', 'tags')
    ->limit(20)
    ->get();
```

## Notas importantes

- `max_matches` sempre entra no `OPTION` do SQL (valor da conexão ou override por método).
- `usingConnection()` invalida cache local de cliente/config no builder para evitar estado incorreto.
- `useIndex()` invalida cache do índice resolvido no builder.
- A biblioteca inclui clientes/respostas “UTF-8 safe” para tratar respostas com encoding inválido.

## Testes

Rodar testes de feature (sem servidor Manticore):

```bash
./vendor/bin/pest tests/Feature
```

Rodar unit test do resolver (sem servidor):

```bash
./vendor/bin/phpunit tests/ManticoreConnectionResolverTest.php
```

Rodar integração do builder (requer Manticore com índice de teste):

```bash
./vendor/bin/phpunit tests/ManticoreBuilderTest.php
```
