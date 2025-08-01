<?php

use Orchestra\Testbench\TestCase;
use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\ManticoreServiceProvider;

class TestModel extends Model
{
    protected $guarded = [];

    public function searchableAs()
    {
        return ['index_1', 'index_2'];
    }
}

class ManticoreBuilderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ManticoreServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('manticore.host', '127.0.0.1');
        $app['config']->set('manticore.port', 9306);
        $app['config']->set('manticore.username', 'root'); 
        $app['config']->set('manticore.password', null);
        $app['config']->set('manticore.transport', 'http');
        $app['config']->set('manticore.timeout', 5);
        $app['config']->set('manticore.persistent', false);
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
            $this->assertNotEmpty($result->countryiso);
            $this->assertGreaterThan(0, $result->total);
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
            $this->assertNotEmpty($result->entityid);
            $this->assertNotEmpty($result->entityname);
        }
    }




}
