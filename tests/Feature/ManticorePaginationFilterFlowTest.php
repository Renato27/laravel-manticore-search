<?php

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ManticoreLaravel\Builder\ManticoreBuilder;

beforeEach(function () {
    config()->set('manticore.pagination.context_key', '_mctx');
    config()->set('manticore.pagination.cache_prefix', 'manticore:test:pagination:');
    config()->set('manticore.pagination.context_ttl', 900);
    config()->set('manticore.pagination.max_query_length', 1500);
});

function makePaginationRows(): array
{
    return [
        ['id' => 1, 'name' => 'One', 'group_id' => 10, 'version' => 'v1'],
        ['id' => 2, 'name' => 'One', 'group_id' => 10, 'version' => 'v2'],
        ['id' => 3, 'name' => 'Two', 'group_id' => 20, 'version' => 'v1'],
        ['id' => 4, 'name' => 'Three', 'group_id' => 30, 'version' => 'v1'],
    ];
}

class PaginationFilterFlowModel extends Model
{
    protected $guarded = [];

    public function searchableAs(): array
    {
        return ['pagination_filter_flow_index'];
    }
}

class FakePaginationFilterFlowBuilder extends ManticoreBuilder
{
    private array $rows = [];

    public function seedRows(array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    protected function fetchRawQuery(): EloquentCollection
    {
        return $this->hydrateModelsFromRows($this->rows);
    }

    protected function getRawRowsForCurrentQuery(): array
    {
        return $this->rows;
    }
}

class FakeWindowLimitedPaginationBuilder extends ManticoreBuilder
{
    private array $rows = [];

    public function seedRows(array $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    protected function getRawRowsForCurrentQuery(): array
    {
        $window = $this->limit ?? 20;

        return array_slice($this->rows, 0, $window);
    }
}

class ExposedTotalExtractorBuilder extends ManticoreBuilder
{
    public function extractTotalPublic(mixed $resultSet, int $fallback = 0): int
    {
        return $this->extractTotalFromResultSet($resultSet, $fallback);
    }

    public function sqlPublic(): string
    {
        return $this->toSql();
    }
}

class ExposedRawRowsBuilder extends ManticoreBuilder
{
    public function extractRowsPublic(mixed $results): array
    {
        return $this->extractRawRows($results);
    }
}

class FakeSqlPaginateBuilder extends ManticoreBuilder
{
    private SplQueue $sqlResponses;

    public function __construct($model)
    {
        parent::__construct($model);

        $this->sqlResponses = new SplQueue();
    }

    public function queueSqlResponse(mixed $response): static
    {
        $this->sqlResponses->enqueue($response);

        return $this;
    }

    protected function executeSqlQuery(string $sql, ?bool $rawMode = null): mixed
    {
        if ($this->sqlResponses->isEmpty()) {
            throw new RuntimeException('No fake SQL response queued for: '.$sql);
        }

        return $this->sqlResponses->dequeue();
    }
}

class FakeSqlResultSet implements IteratorAggregate
{
    public function __construct(
        private array $rows,
        private int|array $total,
    ) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_map(
            fn (array $row) => new FakeSqlResultHit($row),
            $this->rows
        ));
    }

    public function getTotal(): int|array
    {
        return $this->total;
    }
}

class FakeSqlResultHit
{
    public function __construct(private array $data) {}

    public function getData(): array
    {
        return $this->data;
    }

    public function getId(): mixed
    {
        throw new RuntimeException('getId should not be required for grouped SQL rows');
    }

    public function getHighlight(): array
    {
        return [];
    }
}

it('preserves post payload filters on regular pagination links', function () {
    $request = Request::create('/api/search', 'POST', [
        'term' => 'startup',
        'filters' => [
            'country' => 'BR',
            'status' => 'active',
        ],
        'page' => 1,
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows([
            ['id' => 1, 'name' => 'One', 'group_id' => 10],
            ['id' => 2, 'name' => 'Two', 'group_id' => 10],
            ['id' => 3, 'name' => 'Three', 'group_id' => 20],
        ])
        ->rawQuery('SELECT * FROM pagination_filter_flow_index')
        ->paginate(2);

    $nextPageUrl = $paginator->url(2);

    expect($nextPageUrl)
        ->toContain('/api/search?')
        ->toContain('term=startup')
        ->toContain('filters%5Bcountry%5D=BR')
        ->toContain('filters%5Bstatus%5D=active')
        ->toContain('page=2')
        ->not->toContain('page=1');
});

it('preserves get query filters with custom page name on regular pagination links', function () {
    $request = Request::create('/api/search', 'GET', [
        'q' => 'cloud',
        'country' => 'PT',
        'p' => 1,
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows([
            ['id' => 1, 'name' => 'One', 'group_id' => 10],
            ['id' => 2, 'name' => 'Two', 'group_id' => 20],
            ['id' => 3, 'name' => 'Three', 'group_id' => 30],
        ])
        ->rawQuery('SELECT * FROM pagination_filter_flow_index')
        ->paginate(2, 'p');

    $nextPageUrl = $paginator->url(2);

    expect($nextPageUrl)
        ->toContain('/api/search?')
        ->toContain('q=cloud')
        ->toContain('country=PT')
        ->toContain('p=2')
        ->not->toContain('p=1');
});

it('preserves request filters on consolidated pagination links', function () {
    $request = Request::create('/api/search', 'POST', [
        'term' => 'security',
        'filters' => [
            'category' => 'infra',
        ],
        'page' => 1,
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    $nextPageUrl = $paginator->url(2);

    expect($nextPageUrl)
        ->toContain('/api/search?')
        ->toContain('term=security')
        ->toContain('filters%5Bcategory%5D=infra')
        ->toContain('page=2')
        ->not->toContain('page=1');
});

it('keeps the same filter context when flow changes from post to get on next page', function () {
    $postRequest = Request::create('/api/search', 'POST', [
        'term' => 'security',
        'filters' => [
            'category' => 'infra',
            'country' => 'BR',
        ],
        'page' => 1,
    ]);

    app()->instance('request', $postRequest);

    $firstPage = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    $secondPageUrl = $firstPage->url(2);
    $queryString = parse_url($secondPageUrl, PHP_URL_QUERY) ?: '';
    parse_str($queryString, $query);

    $getRequest = Request::create('/api/search', 'GET', $query);
    app()->instance('request', $getRequest);

    $secondPage = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    expect($secondPage->currentPage())->toBe(2)
        ->and($secondPage->first()->group_id)->toBe(20)
        ->and($secondPage->url(3))->toContain('term=security')
        ->and($secondPage->url(3))->toContain('filters%5Bcategory%5D=infra')
        ->and($secondPage->url(3))->toContain('filters%5Bcountry%5D=BR');
});

it('uses a short pagination context token when filters are too large', function () {
    config()->set('manticore.pagination.max_query_length', 80);

    $request = Request::create('/api/search', 'POST', [
        'term' => str_repeat('security-', 40),
        'filters' => [
            'category' => str_repeat('infra-', 40),
            'country' => 'BR',
        ],
        'page' => 1,
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    $nextPageUrl = $paginator->url(2);

    expect($nextPageUrl)
        ->toContain('_mctx=')
        ->toContain('page=2')
        ->not->toContain('term=')
        ->not->toContain('filters%5Bcategory%5D=');
});

it('recovers filter payload from pagination context token on follow-up get request', function () {
    config()->set('manticore.pagination.max_query_length', 80);

    $postRequest = Request::create('/api/search', 'POST', [
        'term' => str_repeat('security-', 40),
        'filters' => [
            'category' => str_repeat('infra-', 40),
            'country' => 'BR',
        ],
        'page' => 1,
    ]);

    app()->instance('request', $postRequest);

    $firstPage = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    $queryString = parse_url($firstPage->url(2), PHP_URL_QUERY) ?: '';
    parse_str($queryString, $nextQuery);

    $getRequest = Request::create('/api/search', 'GET', $nextQuery);

    $resolved = ManticoreBuilder::resolvePaginationInputFromRequest('page', $getRequest);

    expect($resolved)
        ->toHaveKey('term')
        ->and($resolved)->toHaveKey('filters')
        ->and($resolved['filters'])->toHaveKey('category')
        ->and($resolved['filters'])->toHaveKey('country', 'BR')
        ->and($resolved)->toHaveKey('page', '2');
});

it('does not duplicate page parameter when request contains uppercase Page', function () {
    $request = Request::create('/v1/entities', 'GET', [
        'PerPage' => 4,
        'Page' => 1,
        'Filters' => [
            'CountryISO' => 'PT',
        ],
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 1);

    $url = $paginator->url(2);

    expect($url)
        ->toContain('PerPage=4')
        ->toContain('Filters%5BCountryISO%5D=PT')
        ->toContain('page=2')
        ->not->toContain('Page=1');
});

it('resolves current page from uppercase Page request key', function () {
    $request = Request::create('/v1/entities', 'GET', [
        'PerPage' => 2,
        'Page' => 2,
        'Filters' => [
            'CountryISO' => 'PT',
        ],
    ]);

    app()->instance('request', $request);

    $paginator = (new FakePaginationFilterFlowBuilder(new PaginationFilterFlowModel()))
        ->seedRows(makePaginationRows())
        ->paginateConsolidatedBy('group_id', 2, 'page', null);

    expect($paginator->currentPage())->toBe(2)
        ->and($paginator->first()->group_id)->toBe(30);
});

it('recovers full consolidated total in fallback mode when source rows are window limited', function () {
    config()->set('manticore.limit_results', false);
    config()->set('manticore.unlimited_max_matches', 200);

    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
        $rows[] = ['id' => $i, 'group_id' => $i, 'name' => 'Row '.$i];
    }

    $request = Request::create('/v1/entities', 'GET', ['page' => 1]);
    app()->instance('request', $request);

    $paginator = (new FakeWindowLimitedPaginationBuilder(new PaginationFilterFlowModel()))
        ->seedRows($rows)
        ->paginateConsolidatedBy('group_id', 10);

    expect($paginator->total())->toBe(60)
        ->and($paginator->lastPage())->toBe(6);
});

it('recovers full consolidated total in fallback mode with select group by and order by', function () {
    config()->set('manticore.limit_results', false);
    config()->set('manticore.unlimited_max_matches', 200);

    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
        $rows[] = [
            'id' => $i,
            'group_id' => $i,
            'EntityID' => $i,
            'EntityName' => 'Entity '.$i,
            'weight' => 1000 - $i,
        ];
    }

    $request = Request::create('/v1/entities', 'GET', ['page' => 1]);
    app()->instance('request', $request);

    $paginator = (new FakeSqlPaginateBuilder(new PaginationFilterFlowModel()))
        ->queueSqlResponse(new FakeSqlResultSet($rows, ['value' => 60, 'relation' => 'eq']))
        ->select(['*', 'WEIGHT() as weight'])
        ->groupBy('EntityID')
        ->orderBy(['weight' => 'desc', 'EntityName' => 'asc'])
        ->paginateConsolidatedBy('group_id', 10);

    expect($paginator->total())->toBe(60)
        ->and($paginator->lastPage())->toBe(6)
        ->and($paginator->count())->toBe(10);
});

it('extracts total from value payload when result set total is object-style', function () {
    $resultSet = new class {
        public function getTotal(): array
        {
            return ['value' => 87, 'relation' => 'eq'];
        }
    };

    $builder = new ExposedTotalExtractorBuilder(new PaginationFilterFlowModel());

    expect($builder->extractTotalPublic($resultSet, 0))->toBe(87);
});

it('does not throw when hit has no _id and getId is unsafe', function () {
    $hit = new class {
        public function getData(): array
        {
            return ['group_id' => 10, 'name' => 'One'];
        }

        public function getId(): mixed
        {
            throw new \RuntimeException('No _id available');
        }

        public function getHighlight(): array
        {
            return [];
        }
    };

    $builder = new ExposedRawRowsBuilder(new PaginationFilterFlowModel());
    $rows = $builder->extractRowsPublic(new \ArrayIterator([$hit]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKey('group_id', 10)
        ->and($rows[0])->toHaveKey('name', 'One');
});

it('builds sql limit as offset then limit', function () {
    $builder = new ExposedTotalExtractorBuilder(new PaginationFilterFlowModel());

    $sql = $builder
        ->match('nos')
        ->offset(20)
        ->limit(10)
        ->sqlPublic();

    expect($sql)->toContain('LIMIT 20, 10');
});

it('uses wildcard field when match receives only keywords', function () {
    $builder = new ExposedTotalExtractorBuilder(new PaginationFilterFlowModel());

    $sql = $builder
        ->match('nos')
        ->limit(10)
        ->sqlPublic();

    expect($sql)->toContain("MATCH('@* nos')");
});

it('keeps real total when paginating sql mode with select group by and order by', function () {
    $request = Request::create('/api/search', 'GET', ['page' => 1]);
    app()->instance('request', $request);

    $builder = (new FakeSqlPaginateBuilder(new PaginationFilterFlowModel()))
        ->queueSqlResponse(new FakeSqlResultSet([
            ['EntityID' => 10],
            ['EntityID' => 20],
        ], ['value' => 37, 'relation' => 'eq']))
        ->queueSqlResponse(new FakeSqlResultSet([
            ['EntityID' => 10, 'EntityName' => 'Acme', 'weight' => 1200],
            ['EntityID' => 20, 'EntityName' => 'Beta', 'weight' => 1100],
        ], ['value' => 37, 'relation' => 'eq']));

    $paginator = $builder
        ->match('empresa')
        ->select(['*', 'WEIGHT() as weight'])
        ->groupBy('EntityID')
        ->orderBy(['weight' => 'desc', 'EntityName' => 'asc'])
        ->paginate(2);

    expect($paginator->total())->toBe(37)
        ->and($paginator->count())->toBe(2)
        ->and($paginator->first()->EntityID)->toBe(10)
        ->and($paginator->first()->EntityName)->toBe('Acme');
});

it('reuses result set total in consolidated fallback when groupBy matches consolidation field', function () {
    $request = Request::create('/api/search', 'GET', ['page' => 1]);
    app()->instance('request', $request);

    $builder = (new FakeSqlPaginateBuilder(new PaginationFilterFlowModel()))
        ->queueSqlResponse(new FakeSqlResultSet([
            ['EntityID' => 10, 'EntityName' => 'Acme', 'weight' => 1200],
            ['EntityID' => 20, 'EntityName' => 'Beta', 'weight' => 1100],
        ], ['value' => 37, 'relation' => 'eq']));

    $paginator = $builder
        ->match('empresa')
        ->select(['*', 'WEIGHT() as weight'])
        ->groupBy('EntityID')
        ->orderBy(['weight' => 'desc', 'EntityName' => 'asc'])
        ->paginateConsolidatedBy('EntityID', 2);

    expect($paginator->total())->toBe(37)
        ->and($paginator->count())->toBe(2)
        ->and($paginator->first()->EntityID)->toBe(10);
});

it('keeps grouped sql consolidated pagination under one second for large result sets', function () {
    $request = Request::create('/api/search', 'GET', ['page' => 1]);
    app()->instance('request', $request);

    $rows = [];
    for ($i = 1; $i <= 20000; $i++) {
        $rows[] = [
            'EntityID' => $i,
            'EntityName' => 'Entity '.$i,
            'weight' => 50000 - $i,
        ];
    }

    $pageRows = array_slice($rows, 0, 20);

    $builder = (new FakeSqlPaginateBuilder(new PaginationFilterFlowModel()))
        ->queueSqlResponse(new FakeSqlResultSet($pageRows, ['value' => 20000, 'relation' => 'eq']));

    $start = microtime(true);

    $paginator = $builder
        ->match('entity')
        ->select(['*', 'WEIGHT() as weight'])
        ->groupBy('EntityID')
        ->orderBy(['weight' => 'desc', 'EntityName' => 'asc'])
        ->paginateConsolidatedBy('EntityID', 20);

    $elapsed = microtime(true) - $start;

    expect($paginator->total())->toBe(20000)
        ->and($paginator->count())->toBe(20)
        ->and($elapsed)->toBeLessThan(1.0);
});

it('respects per page in consolidated pagination when group field casing differs', function () {
    $request = Request::create('/api/search', 'GET', ['page' => 1, 'PerPage' => 20]);
    app()->instance('request', $request);

    $rows = [];
    for ($i = 1; $i <= 20; $i++) {
        $rows[] = [
            'entityid' => $i,
            'EntityName' => 'Entity '.$i,
            'weight' => 1000 - $i,
        ];
    }

    $builder = (new FakeSqlPaginateBuilder(new PaginationFilterFlowModel()))
        ->queueSqlResponse(new FakeSqlResultSet($rows, ['value' => 1733, 'relation' => 'eq']));

    $paginator = $builder
        ->match('nos')
        ->select(['*', 'WEIGHT() as weight'])
        ->groupBy('EntityID')
        ->orderBy(['weight' => 'desc', 'EntityName' => 'asc'])
        ->paginateConsolidatedBy('EntityID', 20);

    expect($paginator->total())->toBe(1733)
        ->and($paginator->count())->toBe(20)
        ->and($paginator->first()->entityid)->toBe(1);
});
