<?php

require 'vendor/autoload.php';

// Test paginateConsolidatedBy directly
use ManticoreLaravel\Builder\ManticoreBuilder;

class TestProduct {
    protected $table = 'products';
    
    public function getTable() {
        return $this->table;
    }
}

$builder = new ManticoreBuilder(new TestProduct());

// Simulate a query setup without select/groupBy in the main builder
// (so canUseOptimizedConsolidatedPagination could return true if called)
echo "=== Testing consolidated pagination flow ===\n\n";

// Check what the internal methods see
echo "1. canUseOptimizedConsolidatedPagination(): " 
    . ($builder->canUseOptimizedConsolidatedPagination() ? "true" : "false") . "\n";

echo "2. canUseSqlGroupedConsolidatedPagination('EntityID'): " 
    . ($builder->canUseSqlGroupedConsolidatedPagination('EntityID') ? "true" : "false") . "\n";

echo "\n3. usesSqlQueryMode(): " . ($builder->usesSqlQueryMode() ? "true" : "false") . "\n";

echo "4. Empty groupBy? " . (empty($builder->groupBy) ? "yes" : "no") . "\n";
echo "5. Empty select? " . (empty($builder->select) ? "yes" : "no") . "\n";
echo "6. Empty having? " . (empty($builder->having) ? "yes" : "no") . "\n";

// Now test after setting up the internal query clones that would happen
$testBuilder = clone $builder;
$testBuilder->select(['EntityID'])->groupBy(['EntityID'])->limit(20)->offset(0);

echo "\n=== After setting up grouped query (as fetchConsolidatedPageKeyRows does) ===\n";
echo "usesSqlQueryMode(): " . ($testBuilder->usesSqlQueryMode() ? "true" : "false") . "\n";
echo "SQL generated: " . $testBuilder->toSql() . "\n";
