<?php

// Debug script to test consolidated pagination with per-page respect

require 'vendor/autoload.php';

use ManticoreLaravel\Builder\ManticoreBuilder;

// Simulate exact scenario from the issue
// Query with keywords "NOS", consolidate by EntityID, per_page=20

class FakeModel {
    protected $table = 'products';
    
    public function getTable() {
        return $this->table;
    }
}

// Create a mock consolidation result
$mockRows = [];
for ($i = 1; $i <= 1733; $i++) {
    $mockRows[] = [
        'id' => $i,
        'entityid' => 100 + $i,  // Different casing than the groupField parameter
        'name' => "Product $i",
        'description' => "Desc $i",
    ];
}

echo "=== SIMULATING CONSOLIDATED PAGINATION ===\n";
echo "Total rows available: " . count($mockRows) . "\n";
echo "PerPage: 20\n";
echo "GroupField: EntityID (but rows have 'entityid' key)\n\n";

// Simulate what consolidateRawRows should do
$groupField = 'EntityID';
$grouped = [];

foreach ($mockRows as $row) {
    // Check if field exists with exact case
    $value = null;
    
    // Try direct lookup
    if (array_key_exists($groupField, $row)) {
        $value = $row[$groupField];
    } else {
        // Try case-insensitive lookup
        $target = preg_replace('/[^a-z0-9]/', '', strtolower($groupField));
        
        foreach ($row as $key => $k_value) {
            if (!is_string($key)) continue;
            
            if (preg_replace('/[^a-z0-9]/', '', strtolower($key)) === $target) {
                $value = $k_value;
                break;
            }
        }
    }
    
    if ($value !== null) {
        $grouped[(string)$value][] = $row;
    }
}

echo "After grouping by '$groupField':\n";
echo "Number of groups: " . count($grouped) . "\n";
echo "Rows in first group: " . count(reset($grouped) ?? []) . "\n\n";

// Now after consolidation, we'd have count($grouped) consolidated items
// With PerPage=20, page 1 should return min(20, count($grouped)) items

$totalGroups = count($grouped);
$perPage = 20;
$page = 1;
$offset = max(0, ($page - 1) * $perPage);

$itemsOnThisPage = min($perPage, $totalGroups - $offset);

echo "Results for Page 1:\n";
echo "- Total consolidated items: $totalGroups\n";
echo "- Items that should appear on page 1: $itemsOnThisPage\n";
echo "- Expected (should be 20): $itemsOnThisPage\n";

if ($itemsOnThisPage === 1) {
    echo "\n⚠️  BUG REPRODUCED: Only 1 item returned instead of many!\n";
    echo "This means all rows are collapsing into 1 group.\n";
} else {
    echo "\n✅ Looks correct!\n";
}
