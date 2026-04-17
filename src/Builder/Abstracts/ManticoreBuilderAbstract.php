<?php

namespace ManticoreLaravel\Builder\Abstracts;

use Illuminate\Database\Eloquent\Collection;
use ManticoreLaravel\Builder\Concerns\HasConsolidation;
use ManticoreLaravel\Builder\Concerns\HasEloquentIntegration;
use ManticoreLaravel\Builder\Concerns\HasPagination;
use ManticoreLaravel\Builder\Concerns\HasQueryConstraints;
use ManticoreLaravel\Builder\Concerns\HasResultHydration;
use ManticoreLaravel\Builder\Concerns\HasSqlCompilation;
use ManticoreLaravel\Builder\Utils\Utf8SafeSearch;
use ManticoreLaravel\Support\ManticoreManager;
use Manticoresearch\Client;
use Manticoresearch\Search;
use Manticoresearch\Table;

abstract class ManticoreBuilderAbstract
{
    use HasQueryConstraints;
    use HasSqlCompilation;
    use HasResultHydration;
    use HasEloquentIntegration;
    use HasConsolidation;
    use HasPagination;

    // -------------------------------------------------------------------------
    // Query state
    // -------------------------------------------------------------------------

    protected mixed $model;

    /** @var array<string, mixed> */
    protected array $option = [];

    /** @var array<int, array{field: string, keywords: string, boolean: string}> */
    protected array $match = [];

    /** @var array<int, \Manticoresearch\Query> */
    protected array $must = [];

    /** @var array<int, \Manticoresearch\Query> */
    protected array $should = [];

    /** @var array<int, \Manticoresearch\Query> */
    protected array $mustNot = [];

    /** @var array<int, array{col: string, dir: string}> */
    protected array $sort = [];

    /** @var array<string, array> */
    protected array $aggregations = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected bool $highlight = false;

    protected ?string $rawQuery = null;

    protected bool $rawQueryMode = false;

    /** @var array<int, string> */
    protected array $groupBy = [];

    /** @var array<int, string> */
    protected array $select = [];

    /** @var array<int, string> */
    protected array $having = [];

    /** @var array<string, mixed> */
    protected array $scriptFields = [];

    /**
     * Ordered sequence of where conditions, used for SQL compilation.
     *
     * @var array<int, array{boolean: string, negated: bool, condition: mixed, raw?: string}>
     */
    protected array $whereSequence = [];

    /** @var array<int, array{name: string, closure: \Closure|null}> */
    protected array $eagerQueue = [];

    /** @var array|string|null */
    protected array|string|null $indexOverride = null;

    protected ?string $connectionName = null;

    // -------------------------------------------------------------------------
    // Lazily resolved / cached per-instance state
    // -------------------------------------------------------------------------

    /** @var Client|null */
    private ?Client $client = null;

    /** @var array|null */
    private ?array $resolvedConnectionConfig = null;

    /** @var string|null */
    private ?string $resolvedIndexName = null;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function __construct(mixed $model)
    {
        $this->model = $model;
    }

    // -------------------------------------------------------------------------
    // Connection & client resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the active connection configuration through the centralized resolver.
     *
     * @return array{host: string, port: int, username: string|null, password: string|null, transport: string, timeout: int, persistent: bool, max_matches: int}
     */
    protected function resolveConnectionConfig(): array
    {
        if ($this->resolvedConnectionConfig === null) {
            $this->resolvedConnectionConfig = app(ManticoreManager::class)
                ->resolveConfig($this->connectionName);
        }

        return $this->resolvedConnectionConfig;
    }

    /**
     * Resolve the target Manticore index name.
     * Priority: explicit override → model searchableAs() → Eloquent table name.
     */
    protected function resolveIndexName(): string
    {
        if ($this->resolvedIndexName !== null) {
            return $this->resolvedIndexName;
        }

        if ($this->indexOverride !== null) {
            return $this->resolvedIndexName = is_array($this->indexOverride)
                ? implode(',', $this->indexOverride)
                : $this->indexOverride;
        }

        if (method_exists($this->model, 'searchableAs')) {
            $indexes = $this->model->searchableAs();

            return $this->resolvedIndexName = is_array($indexes)
                ? implode(',', $indexes)
                : (string) $indexes;
        }

        return $this->resolvedIndexName = $this->model->getTable();
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = app(ManticoreManager::class)->client($this->connectionName);
        }

        return $this->client;
    }

    protected function flushResolvedConnectionState(): void
    {
        $this->client                  = null;
        $this->resolvedConnectionConfig = null;
    }

    protected function flushResolvedIndexState(): void
    {
        $this->resolvedIndexName = null;
    }

    protected function getTable(): Table
    {
        $table = new Table($this->getClient());
        $table->setName($this->resolveIndexName());

        return $table;
    }

    // -------------------------------------------------------------------------
    // Query execution primitives
    // -------------------------------------------------------------------------

    /**
     * Execute a SQL query against Manticore.
     *
     * By default (httpRawMode = false) this uses the standard SQL endpoint and returns a ResultSet.
     *
     * When httpRawMode = true the request is sent to the raw SQL endpoint (`mode=raw`), which
     * returns a plain array of rows instead of a ResultSet.  This is only used by fetchRawQuery()
     * and getRawRowsForCurrentQuery() when the caller set rawQuery($sql, rawMode: true).
     */
    protected function executeSqlQuery(string $sql, bool $httpRawMode = false): mixed
    {
        if ($httpRawMode) {
            // Raw HTTP mode: Manticore returns a plain row array, not a ResultSet.
            return $this->getClient()->sql($sql, false, true);
        }

        // Normal mode: always return a ResultSet so extractRawRows() can iterate it.
        return $this->getClient()->sql($sql, true);
    }

    protected function fetchRawQuery(): Collection
    {
        $results = $this->executeSqlQuery($this->rawQuery, $this->rawQueryMode);
        $rows    = $this->extractRawRows($results);
        $models  = $this->hydrateModelsFromRows($rows);

        return $this->applyEloquentWith($models);
    }

    protected function resolveResults(mixed $results): Collection
    {
        $rows = $this->extractRawRows($results);

        return $this->hydrateModelsFromRows($rows);
    }

    // -------------------------------------------------------------------------
    // Search API builder
    // -------------------------------------------------------------------------

    /**
     * Build and configure a Manticore Search object from current builder state.
     */
    protected function search(): Search
    {
        $client = $this->getClient();
        $search = new Utf8SafeSearch($client);

        $this->applyIndex($search);

        $bool = new \Manticoresearch\Query\BoolQuery();

        foreach ($this->match as $m) {
            $bool->must(new \Manticoresearch\Query\MatchQuery($m['keywords'], $m['field']));
        }

        foreach ($this->must as $filter) {
            $bool->must($filter);
        }

        foreach ($this->should as $filter) {
            $bool->should($filter);
        }

        foreach ($this->mustNot as $filter) {
            $bool->mustNot($filter);
        }

        $search->search($bool);

        if ($this->limit !== null) {
            $search->limit($this->limit);
        }

        if ($this->offset !== null) {
            $search->offset($this->offset);
        }

        foreach ($this->sort as $s) {
            foreach ($s as $field => $dir) {
                $search->sort($field, $dir);
            }
        }

        if ($this->highlight) {
            $search->highlight(['*' => new \stdClass()]);
        }

        foreach ($this->aggregations as $name => $agg) {
            $search->facet($agg['terms']['field'], $name);
        }

        foreach ($this->option as $key => $value) {
            $search->option($key, $value);
        }

        return $search;
    }

    /**
     * Apply the resolved index name to a Search instance.
     *
     * NOTE: We use Reflection to set the internal 'index' parameter on the Search
     * object because the Manticore PHP client does not expose a public setter that
     * keeps both 'table' and 'index' params in sync (required for multi-index syntax).
     * This is a known limitation of the upstream client; the reflection access
     * is intentional and must be revisited if the client adds a public API for this.
     */
    private function applyIndex(Search $search): void
    {
        $indexName = $this->resolveIndexName();
        $search->setTable($indexName);

        $ref    = new \ReflectionClass($search);
        $prop   = $ref->getProperty('params');
        $prop->setAccessible(true);
        $params           = $prop->getValue($search);
        $params['index']  = $params['table'] ?? $indexName;
        $prop->setValue($search, $params);
    }

    /**
     * Returns true when the query must go through the SQL path instead of the Search API.
     */
    protected function usesSqlQueryMode(): bool
    {
        return !empty($this->groupBy) || !empty($this->having) || !empty($this->select);
    }
}
