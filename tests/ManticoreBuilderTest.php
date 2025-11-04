<?php

use Orchestra\Testbench\TestCase;
use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\ManticoreServiceProvider;

class TestModel extends Model
{
    protected $guarded = [];

    protected $fillable = [
        'EntityID',
        'EntityName',
        'CountryISO',
    ];

    public function searchableAs()
    {
        return ['ttrentitytest'];
    }
}

class ManticoreBuilderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ManticoreServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('manticore.host', '127.0.0.1');
        $app['config']->set('manticore.port', 9312);
        $app['config']->set('manticore.username', 'root'); 
        $app['config']->set('manticore.password', null);
        $app['config']->set('manticore.transport', 'http');
        $app['config']->set('manticore.timeout', 5);
        $app['config']->set('manticore.persistent', false);
        $app['config']->set('manticore.max_matches', 10000);
    }

    protected function defineEnvironment($app)
    {
       $this->getEnvironmentSetUp($app);
    }

    public function it_applies_max_matches_from_parameter()
    {
        $builder = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->maxMatches(20000) 
            ->limit(10);

        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('buildSqlQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($builder);
        
        $this->assertStringContainsString(
            'OPTION max_matches=20000',
            $sql,
            'max_matches defined via parameter was not propagated to the query'
        );
    }

    public function it_applies_max_matches_from_config_when_not_overridden()
    {

        config(['manticore.max_matches' => 8000]);

        $builder = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->limit(10);

        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('buildSqlQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($builder);

        $this->assertStringContainsString(
            'OPTION max_matches=8000',
            $sql,
            'max_matches from config was not applied when no explicit value was passed'
        );
    }

    public function test_option_method_adds_custom_options_to_query()
    {
        $builder = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->option('ranker', 'bm25')
            ->option('field_weights', '(title=10,content=1)')
            ->limit(10);

        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('buildSqlQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($builder);
        
        $this->assertStringContainsString(
            'OPTION max_matches=',
            $sql,
            'Default max_matches option should be present'
        );
        
        $this->assertStringContainsString(
            'ranker=bm25',
            $sql,
            'Custom ranker option was not added to the query'
        );
        
        $this->assertStringContainsString(
            'field_weights=(title=10,content=1)',
            $sql,
            'Custom field_weights option was not added to the query'
        );
    }

    public function test_option_method_handles_boolean_values()
    {
        $builder = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->option('sort_method', 'pq')
            ->option('accurate_aggregation', true)
            ->option('threads', false)
            ->limit(10);

        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('buildSqlQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($builder);
        
        $this->assertStringContainsString(
            'sort_method=pq',
            $sql,
            'String option value was not properly added'
        );
        
        $this->assertStringContainsString(
            'accurate_aggregation=1',
            $sql,
            'Boolean true option was not converted to 1'
        );
        
        $this->assertStringContainsString(
            'threads=0',
            $sql,
            'Boolean false option was not converted to 0'
        );
    }

    public function test_option_method_with_multiple_options()
    {
        $builder = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->option('ranker', 'bm25')
            ->option('max_query_time', '1000')
            ->option('retry_count', '3')
            ->limit(10);

        $ref = new \ReflectionClass($builder);
        $method = $ref->getMethod('buildSqlQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($builder);
        
        $this->assertStringContainsString(
            'ranker=bm25',
            $sql,
            'First option should be included'
        );
        
        $this->assertStringContainsString(
            'max_query_time=1000',
            $sql,
            'Second option should be included'
        );
        
        $this->assertStringContainsString(
            'retry_count=3',
            $sql,
            'Third option should be included'
        );
        
        // Verify options are comma-separated
        $this->assertMatchesRegularExpression(
            '/OPTION.*ranker=bm25.*,.*max_query_time=1000.*,.*retry_count=3/',
            $sql,
            'Options should be comma-separated'
        );
    }

    public function test_basic_match_query()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->limit(5)
            ->get();

        $this->assertEquals(5, count($results));
        $this->assertIsIterable($results);
    }

    public function test_paginate_works()
    {
        $paginator = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->paginate(5);

        $this->assertEquals(5, $paginator->perPage());
        $this->assertGreaterThan(0, $paginator->total());
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $paginator);
    }


    public function test_where_equals()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->where('countryiso', 'PT')
            ->limit(3)
            ->get();

        $this->assertEquals(3, count($results));
        $this->assertIsIterable($results);
    }

    public function test_where_not_equals()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->where('countryiso', '!=', 'PT')
            ->limit(3)
            ->get();

        $this->assertEquals(3, count($results));
        $this->assertIsIterable($results);
    }

    public function test_or_where_combination()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->where('countryiso', 'PT')
            ->orWhere('countryiso', 'ES')
            ->limit(5)
            ->get();

        $this->assertLessThanOrEqual(5, $results->count());
    }

    public function test_where_between()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->whereBetween('entityid', [1, 999999999])
            ->limit(3)
            ->get();

        $this->assertIsIterable($results);
    }

    public function test_to_array_and_json()
    {
        $builder = new ManticoreBuilder(new TestModel());
        $array = $builder->match('Portugal')->limit(2)->toArray();
        $json = $builder->match('Portugal')->limit(2)->toJson();
        
        $this->assertIsArray($array);
        $this->assertJson($json);
    }

    public function test_first_and_last()
    {
        $builder = new ManticoreBuilder(new TestModel());
        $first = $builder->match('Portugal')->limit(3)->first();
        $last = $builder->match('Portugal')->limit(3)->last();
        
        $this->assertNotNull($first);
        $this->assertNotNull($last);
    }

    public function test_count()
    {
        $count = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->limit(10)
            ->count();
        
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_raw_query_mode()
    {
        $indexName = (new TestModel())->searchableAs();
        $indexName = implode(',', $indexName);
        $results = (new ManticoreBuilder(new TestModel()))
            ->rawQuery("SELECT * FROM {$indexName}  WHERE countryiso = 'PT' LIMIT 2")
            ->get();
        
        $this->assertIsIterable($results);
        $this->assertLessThanOrEqual(2, $results->count());
    }

    public function test_to_sql_generation()
    {
        $sql = (new ManticoreBuilder(new TestModel()))
            ->match('Portugal')
            ->whereBetween('entityid', [1, 999999999])
            ->where('countryiso', 'PT')
            ->limit(1)
            ->toSql();
        
        $this->assertIsString($sql);
        $this->assertStringContainsString("MATCH('@* Portugal')", $sql);
        $this->assertStringContainsString("countryiso", $sql);
        $this->assertStringContainsString("PT", $sql);
        $this->assertMatchesRegularExpression('/LIMIT\s+\d+,\s*1/', $sql);

    }

    public function test_it_can_return_aggregations()
    {
        $facets = (new ManticoreBuilder(new TestModel()))
            ->aggregate('countryiso_agg', [
                'terms' => [
                    'field' => 'countryiso',
                    'size' => 5,
                ]
            ])
            ->getFacets();

        $this->assertIsArray($facets);
        $this->assertArrayHasKey('countryiso_agg', $facets);
        $this->assertArrayHasKey('buckets', $facets['countryiso_agg']);
    }

    public function test_group_by_works()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->select(['countryiso', 'COUNT(*) as total'])
            ->groupBy('countryiso')
            ->limit(5)
            ->get();
        
        $this->assertIsIterable($results);
        $this->assertLessThanOrEqual(5, $results->count());

        foreach ($results as $result) {
            $this->assertNotEmpty($result->countryiso ?? $result->CountryISO);
            $this->assertGreaterThan(0, $result->total);
        }
    }

    public function test_order_by_works()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->whereBetween('entityid', [1, 999999999])
            ->orderBy('entityid', 'desc')
            ->limit(5)
            ->get();

        $this->assertIsIterable($results);
        $this->assertLessThanOrEqual(5, $results->count());

        $previousEntityId = PHP_INT_MAX;
        foreach ($results as $result) {
            $currentEntityId = $result->entityid ?? $result->EntityID;
            $this->assertLessThanOrEqual($previousEntityId, $currentEntityId);
            $previousEntityId = $currentEntityId;
        }
    }

    public function test_having_clause_works()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->select(['entityid', 'COUNT(*) as total'])
            ->groupBy('entityid')
            ->having("COUNT(*) > 1")
            ->limit(5)
            ->get();

        $this->assertIsIterable($results);

        foreach ($results as $result) {
            $this->assertGreaterThan(1, $result->total);
        }
    }

    public function test_select_clause_works()
    {
        $results = (new ManticoreBuilder(new TestModel()))
            ->select(['entityid', 'entityname'])
            ->whereBetween('entityid', [1, 999999999])
            ->limit(3)
            ->get();
        
        $this->assertIsIterable($results);
        foreach ($results as $result) {
            $this->assertNotEmpty($result->entityid ?? $result->EntityID);
            $this->assertNotEmpty($result->entityname ?? $result->EntityName);
        }
    }

    public function it_returns_a_search_instance()
    {
        $builder = new ManticoreBuilder(new TestModel());

        $search = $builder->getSearchInstance();

        $this->assertInstanceOf(\Manticoresearch\Search::class, $search);
    }

    public function builder_method_returns_the_same_instance()
    {
        $builder = new ManticoreBuilder(new TestModel());

        $this->assertSame($builder, $builder->builder());
    }

    public function it_returns_a_table_instance()
    {
        $builder = new ManticoreBuilder(new TestModel());

        $table = $builder->getTableInstance();

        $this->assertInstanceOf(\Manticoresearch\Table::class, $table);
    }

    public function it_returns_a_client_instance()
    {
        $builder = new ManticoreBuilder(new TestModel());

        $client = $builder->getClientInstance();

        $this->assertInstanceOf(\Manticoresearch\Client::class, $client);
    }


}
