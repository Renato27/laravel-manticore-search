<?php

use ManticoreLaravel\Builder\ManticoreBuilder;

function optionSql(ManticoreBuilder $b): string
{
    $ref = new ReflectionClass($b);
    $m   = $ref->getMethod('buildSqlQuery');
    $m->setAccessible(true);
    return $m->invoke($b);
}

it('maxMatches sets max_matches option', function () {
    $sql = optionSql(makeBuilder()->maxMatches(20000)->limit(5));
    expect($sql)->toContain('max_matches=20000');
});

it('option() sets arbitrary option', function () {
    $sql = optionSql(makeBuilder()->option('ranker', 'bm25')->limit(5));
    expect($sql)->toContain('ranker=bm25');
});

it('max_matches appears exactly once', function () {
    $sql = optionSql(makeBuilder()->maxMatches(5000)->limit(5));
    expect(substr_count($sql, 'max_matches='))->toBe(1);
});

it('option with array value generates parenthesized list', function () {
    $sql = optionSql(makeBuilder()->option('field_weights', ['title' => 10, 'body' => 1])->limit(5));
    expect($sql)->toContain('field_weights=(title=10,body=1)');
});

it('option with boolean true generates 1', function () {
    $sql = optionSql(makeBuilder()->option('accurate_aggregation', true)->limit(5));
    expect($sql)->toContain('accurate_aggregation=1');
});

it('option with boolean false generates 0', function () {
    $sql = optionSql(makeBuilder()->option('threads', false)->limit(5));
    expect($sql)->toContain('threads=0');
});

it('multiple options are comma-separated', function () {
    $sql = optionSql(makeBuilder()->option('ranker', 'bm25')->option('max_query_time', 1000)->limit(5));
    expect($sql)->toMatch('/OPTION.*ranker=bm25.*,.*max_query_time=1000/');
});
