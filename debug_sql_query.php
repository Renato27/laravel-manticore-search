<?php

require 'vendor/autoload.php';

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

class TestProduct extends Model {
    protected $table = 'products';
    public function getTable() {
        return $this->table;
    }
}

$builder = new ManticoreBuilder(new TestProduct());

// Simulate the exact query from the failing test
$builder
    ->match('nos')
    ->select(['*', 'WEIGHT() as weight'])
    ->groupBy('EntityID')
    ->orderBy(['weight' => 'desc', 'EntityName' => 'asc']);

echo "SQL Query:\n";
echo $builder->toSql() . "\n";
echo "\n";

// Now with limit/offset applied as would happen in paginateConsolidatedSqlGrouped
$builder2 = clone $builder;
$builder2->limit(20)->offset(0);
$builder2->option('accurate_aggregation', 1);
$builder2->option('max_matches', 1000000);

echo "SQL Query with pagination:\n";
echo $builder2->toSql() . "\n";
