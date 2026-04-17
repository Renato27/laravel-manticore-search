<?php

use ManticoreLaravel\Builder\ManticoreBuilder;

function builderSql(ManticoreBuilder $b): string
{
    $ref = new ReflectionClass($b);
    $m   = $ref->getMethod('buildSqlQuery');
    $m->setAccessible(true);
    return $m->invoke($b);
}

it('whereNot with > operator produces <=', function () {
    $sql = builderSql(makeBuilder()->whereNot('score', '>', 100)->limit(5));
    expect($sql)->toContain('`score` <= 100');
});

it('whereNot with >= operator produces <', function () {
    $sql = builderSql(makeBuilder()->whereNot('score', '>=', 100)->limit(5));
    expect($sql)->toContain('`score` < 100');
});

it('whereNot with < operator produces >=', function () {
    $sql = builderSql(makeBuilder()->whereNot('score', '<', 50)->limit(5));
    expect($sql)->toContain('`score` >= 50');
});

it('whereNot with <= operator produces >', function () {
    $sql = builderSql(makeBuilder()->whereNot('score', '<=', 50)->limit(5));
    expect($sql)->toContain('`score` > 50');
});

it('chains multiple where and whereNot', function () {
    $sql = builderSql(
        makeBuilder()
            ->where('status', 'active')
            ->whereNot('type', 'spam')
            ->limit(5)
    );
    expect($sql)
        ->toContain("`status` = 'active'")
        ->toContain("`type` <> 'spam'");
});

it('orWhere after where uses OR boolean', function () {
    $sql = builderSql(
        makeBuilder()
            ->where('status', 'active')
            ->orWhere('status', 'pending')
            ->limit(5)
    );
    expect($sql)->toContain('OR');
});

it('whereIn with integers', function () {
    $sql = builderSql(makeBuilder()->whereIn('id', [1, 2, 3])->limit(5));
    expect($sql)->toContain('`id` IN (1, 2, 3)');
});

it('whereNotIn with strings', function () {
    $sql = builderSql(makeBuilder()->whereNotIn('status', ['deleted', 'banned'])->limit(5));
    expect($sql)->toContain("`status` NOT IN ('deleted', 'banned')");
});

it('whereBetween generates gte and lte', function () {
    $sql = builderSql(makeBuilder()->whereBetween('score', [10, 90])->limit(5));
    expect($sql)->toContain('`score` >= 10')->toContain('`score` <= 90');
});

it('whereNull generates IS NULL', function () {
    $sql = builderSql(makeBuilder()->whereNull('deleted_at')->limit(5));
    expect($sql)->toContain('`deleted_at` IS NULL');
});

it('whereNotNull generates IS NOT NULL', function () {
    $sql = builderSql(makeBuilder()->whereNotNull('published_at')->limit(5));
    expect($sql)->toContain('`published_at` IS NOT NULL');
});

it('whereRaw injects raw SQL', function () {
    $sql = builderSql(makeBuilder()->whereRaw("MATCH('hello')")->limit(5));
    expect($sql)->toContain("MATCH('hello')");
});

it('multiple whereRaw with OR boolean', function () {
    $sql = builderSql(
        makeBuilder()
            ->whereRaw('score > 100')
            ->whereRaw('score < 10', 'OR')
            ->limit(5)
    );
    expect($sql)->toContain('OR')->toContain('score > 100')->toContain('score < 10');
});
