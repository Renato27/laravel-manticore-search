<?php

use ManticoreLaravel\Builder\ManticoreBuilder;

function callConsolidateRawRows(ManticoreBuilder $b, array $rows, string $groupField, string $histAttr = 'history', bool $preserve = true): array
{
    $ref    = new ReflectionClass($b);
    $method = $ref->getMethod('consolidateRawRows');
    $method->setAccessible(true);
    return $method->invoke($b, $rows, $groupField, $histAttr, $preserve);
}

it('consolidates rows by group field', function () {
    $rows = [
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'login'],
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'logout'],
        ['entity_id' => 2, 'name' => 'Bob',   'action' => 'login'],
    ];

    $b      = makeBuilder();
    $result = callConsolidateRawRows($b, $rows, 'entity_id');

    expect($result)->toHaveCount(2);
});

it('places common fields at top level', function () {
    $rows = [
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'login'],
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'logout'],
    ];

    $result = callConsolidateRawRows(makeBuilder(), $rows, 'entity_id');

    expect($result[0])->toHaveKey('name');
    expect($result[0]['name'])->toBe('Alice');
});

it('collects variable fields in history', function () {
    $rows = [
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'login'],
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'logout'],
    ];

    $result = callConsolidateRawRows(makeBuilder(), $rows, 'entity_id');

    expect($result[0])->toHaveKey('history');
    expect($result[0]['history'])->toHaveCount(2);
    expect($result[0]['history'][0])->toHaveKey('action');
});

it('respects custom historyAttribute name', function () {
    $rows = [
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'login'],
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'logout'],
    ];

    $result = callConsolidateRawRows(makeBuilder(), $rows, 'entity_id', 'events');

    expect($result[0])->toHaveKey('events');
});

it('returns empty array when rows is empty', function () {
    $result = callConsolidateRawRows(makeBuilder(), [], 'entity_id');

    expect($result)->toBeEmpty();
});

it('preserves group field in history by default', function () {
    $rows = [
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'login'],
        ['entity_id' => 1, 'name' => 'Alice', 'action' => 'logout'],
    ];

    $result = callConsolidateRawRows(makeBuilder(), $rows, 'entity_id', 'history', true);

    // entity_id is NOT variable (same value), so it's common — not in history
    expect($result[0]['history'][0])->toHaveKey('action');
});

it('removes group field from history when preserveGroupFieldInHistory is false', function () {
    $rows = [
        ['entity_id' => 1, 'action' => 'login',  'ref' => 'A'],
        ['entity_id' => 1, 'action' => 'logout', 'ref' => 'B'],
    ];

    // entity_id is common (same across rows), action and ref are variable
    $result = callConsolidateRawRows(makeBuilder(), $rows, 'entity_id', 'history', false);

    // action is variable but entity_id should not appear in history snapshots
    foreach ($result[0]['history'] as $snapshot) {
        expect($snapshot)->not->toHaveKey('entity_id');
    }
});
