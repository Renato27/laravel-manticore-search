<?php

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function buildSql(ManticoreBuilder $builder): string
{
    $ref    = new ReflectionClass($builder);
    $method = $ref->getMethod('buildSqlQuery');
    $method->setAccessible(true);
    return $method->invoke($builder);
}

// ---------------------------------------------------------------------------
// SELECT clause
// ---------------------------------------------------------------------------

it('generates SELECT * by default', function () {
    $sql = buildSql(makeBuilder()->limit(5));
    expect($sql)->toContain('SELECT *');
});

it('generates SELECT with specific fields', function () {
    $sql = buildSql(makeBuilder()->select(['id', 'title'])->limit(5));
    expect($sql)->toContain('SELECT id, title');
});

it('wraps index name in backticks', function () {
    $sql = buildSql(makeBuilder('my_index')->limit(1));
    expect($sql)->toContain('FROM `my_index`');
});

// ---------------------------------------------------------------------------
// WHERE clause
// ---------------------------------------------------------------------------

it('generates WHERE equals', function () {
    $sql = buildSql(makeBuilder()->where('status', 'active')->limit(5));
    expect($sql)->toContain("`status` = 'active'");
});

it('generates WHERE with explicit = operator', function () {
    $sql = buildSql(makeBuilder()->where('score', '=', 10)->limit(5));
    expect($sql)->toContain('`score` = 10');
});

it('generates WHERE with != operator as <>', function () {
    $sql = buildSql(makeBuilder()->where('status', '!=', 'deleted')->limit(5));
    expect($sql)->toContain("`status` <> 'deleted'");
});

it('generates WHERE with <> operator (native alias for !=)', function () {
    $sql = buildSql(makeBuilder()->where('status', '<>', 'deleted')->limit(5));
    expect($sql)->toContain("`status` <> 'deleted'");
});

it('generates WHERE with > operator', function () {
    $sql = buildSql(makeBuilder()->where('score', '>', 100)->limit(5));
    expect($sql)->toContain('`score` > 100');
});

it('generates WHERE with >= operator', function () {
    $sql = buildSql(makeBuilder()->where('score', '>=', 100)->limit(5));
    expect($sql)->toContain('`score` >= 100');
});

it('generates WHERE with lt operator', function () {
    $sql = buildSql(makeBuilder()->where('score', '<', 50)->limit(5));
    expect($sql)->toContain('`score` < 50');
});

it('generates WHERE with lte operator', function () {
    $sql = buildSql(makeBuilder()->where('score', '<=', 50)->limit(5));
    expect($sql)->toContain('`score` <= 50');
});

it('generates WHERE NOT via whereNot', function () {
    $sql = buildSql(makeBuilder()->whereNot('status', 'deleted')->limit(5));
    expect($sql)->toContain("`status` <> 'deleted'");
});

it('generates WHERE IN', function () {
    $sql = buildSql(makeBuilder()->whereIn('status', ['active', 'pending'])->limit(5));
    expect($sql)->toContain("`status` IN ('active', 'pending')");
});

it('generates WHERE NOT IN', function () {
    $sql = buildSql(makeBuilder()->whereNotIn('status', ['deleted', 'banned'])->limit(5));
    expect($sql)->toContain("`status` NOT IN ('deleted', 'banned')");
});

it('generates WHERE BETWEEN', function () {
    $sql = buildSql(makeBuilder()->whereBetween('score', [10, 100])->limit(5));
    expect($sql)->toContain('`score` >= 10')->toContain('`score` <= 100');
});

it('generates WHERE IS NULL', function () {
    $sql = buildSql(makeBuilder()->whereNull('deleted_at')->limit(5));
    expect($sql)->toContain('`deleted_at` IS NULL');
});

it('generates WHERE IS NOT NULL', function () {
    $sql = buildSql(makeBuilder()->whereNotNull('published_at')->limit(5));
    expect($sql)->toContain('`published_at` IS NOT NULL');
});

it('generates WHERE with whereRaw', function () {
    $sql = buildSql(makeBuilder()->whereRaw("geodist(lat, lon, 48.8, 2.3) < 10000")->limit(5));
    expect($sql)->toContain('geodist(lat, lon, 48.8, 2.3) < 10000');
});

it('generates AND between multiple where clauses', function () {
    $sql = buildSql(makeBuilder()->where('status', 'active')->where('score', '>', 5)->limit(5));
    expect($sql)->toContain('AND');
});

it('generates OR WHERE correctly', function () {
    $sql = buildSql(makeBuilder()->where('status', 'active')->orWhere('status', 'pending')->limit(5));
    expect($sql)->toContain('OR');
});

// ---------------------------------------------------------------------------
// MATCH clause
// ---------------------------------------------------------------------------

it('generates MATCH clause in WHERE', function () {
    $sql = buildSql(makeBuilder()->match('hello world')->limit(5));
    expect($sql)->toContain("MATCH('(@* (hello world))')");
});

it('generates MATCH on specific field', function () {
    $sql = buildSql(makeBuilder()->match('hello', 'title')->limit(5));
    expect($sql)->toContain("MATCH('(@title (hello))')");
});

it('combines MATCH and WHERE filters', function () {
    $sql = buildSql(makeBuilder()->match('hello')->where('status', 'active')->limit(5));
    expect($sql)->toContain('MATCH(')->toContain("`status` = 'active'");
});

// ---------------------------------------------------------------------------
// GROUP BY / HAVING
// ---------------------------------------------------------------------------

it('generates GROUP BY clause', function () {
    $sql = buildSql(makeBuilder()->select(['status', 'COUNT(*) as total'])->groupBy('status')->limit(5));
    expect($sql)->toContain('GROUP BY status');
});

it('generates HAVING clause', function () {
    $sql = buildSql(makeBuilder()->select(['status', 'COUNT(*) as total'])->groupBy('status')->having('COUNT(*) > 1')->limit(5));
    expect($sql)->toContain('HAVING COUNT(*) > 1');
});

// ---------------------------------------------------------------------------
// ORDER BY
// ---------------------------------------------------------------------------

it('generates ORDER BY asc', function () {
    $sql = buildSql(makeBuilder()->orderBy('score', 'asc')->limit(5));
    expect($sql)->toContain('ORDER BY `score` ASC');
});

it('generates ORDER BY desc', function () {
    $sql = buildSql(makeBuilder()->orderBy('score', 'desc')->limit(5));
    expect($sql)->toContain('ORDER BY `score` DESC');
});

it('generates multiple ORDER BY columns from array', function () {
    $sql = buildSql(makeBuilder()->orderBy(['score' => 'desc', 'id' => 'asc'])->limit(5));
    expect($sql)->toContain('ORDER BY `score` DESC, `id` ASC');
});

// ---------------------------------------------------------------------------
// LIMIT / OFFSET
// ---------------------------------------------------------------------------

it('generates LIMIT clause', function () {
    $sql = buildSql(makeBuilder()->limit(10));
    expect($sql)->toContain('LIMIT 10');
});

it('generates LIMIT with offset', function () {
    $sql = buildSql(makeBuilder()->limit(10)->offset(20));
    expect($sql)->toContain('LIMIT 20, 10');
});

it('forPage sets correct limit and offset', function () {
    $builder = makeBuilder()->forPage(3, 10);
    $sql = buildSql($builder);
    expect($sql)->toContain('LIMIT 20, 10');
});

// ---------------------------------------------------------------------------
// OPTION clause
// ---------------------------------------------------------------------------

it('includes max_matches in OPTION', function () {
    $sql = buildSql(makeBuilder()->maxMatches(5000)->limit(5));
    expect($sql)->toContain('OPTION max_matches=5000');
});

it('max_matches appears exactly once', function () {
    $sql = buildSql(makeBuilder()->maxMatches(5000)->option('max_matches', 3000)->limit(5));
    // Last set value wins, appears once
    expect(substr_count($sql, 'max_matches='))->toBe(1);
});

it('includes custom options in OPTION clause', function () {
    $sql = buildSql(makeBuilder()->option('ranker', 'bm25')->option('field_weights', '(title=10)')->limit(5));
    expect($sql)->toContain('ranker=bm25')->toContain('field_weights=(title=10)');
});

it('converts boolean true option to 1', function () {
    $sql = buildSql(makeBuilder()->option('accurate_aggregation', true)->limit(5));
    expect($sql)->toContain('accurate_aggregation=1');
});

it('converts boolean false option to 0', function () {
    $sql = buildSql(makeBuilder()->option('threads', false)->limit(5));
    expect($sql)->toContain('threads=0');
});

// ---------------------------------------------------------------------------
// toSql / method chaining
// ---------------------------------------------------------------------------

it('toSql returns the compiled SQL', function () {
    $sql = makeBuilder()->where('status', 'active')->limit(5)->toSql();
    expect($sql)->toBeString()->toContain('SELECT')->toContain('WHERE');
});

it('builder() returns same instance', function () {
    $b = makeBuilder();
    expect($b->builder())->toBe($b);
});

it('tap() does not break the chain', function () {
    $tapped = false;
    $b = makeBuilder()->tap(function () use (&$tapped) {
        $tapped = true;
    });
    expect($tapped)->toBeTrue()->and($b)->toBeInstanceOf(ManticoreBuilder::class);
});

it('when() applies callback when condition is true', function () {
    $sql = buildSql(makeBuilder()->when(true, fn($q) => $q->where('status', 'active'))->limit(5));
    expect($sql)->toContain('active');
});

it('when() skips callback when condition is false', function () {
    $sql = buildSql(makeBuilder()->when(false, fn($q) => $q->where('status', 'active'))->limit(5));
    expect($sql)->not->toContain('active');
});

it('when() applies default callback when condition is false', function () {
    $sql = buildSql(makeBuilder()->when(false, fn($q) => $q->where('status', 'active'), fn($q) => $q->where('status', 'inactive'))->limit(5));
    expect($sql)->toContain('inactive');
});

// ---------------------------------------------------------------------------
// usingConnection / useIndex
// ---------------------------------------------------------------------------

it('usingConnection sets connectionName property', function () {
    $b = makeBuilder()->usingConnection('analytics');
    $ref = new ReflectionClass($b);
    $prop = $ref->getProperty('connectionName');
    $prop->setAccessible(true);
    expect($prop->getValue($b))->toBe('analytics');
});

it('usingConnection is chainable', function () {
    $b = makeBuilder();
    expect($b->usingConnection('analytics'))->toBe($b);
});

it('useIndex overrides resolved index name', function () {
    $b = makeBuilder('original_index');
    $b->useIndex('override_index');
    $sql = buildSql($b->limit(1));
    expect($sql)->toContain('`override_index`');
});

it('useIndex with array joins index names', function () {
    $b = makeBuilder();
    $b->useIndex(['index_a', 'index_b']);
    $sql = buildSql($b->limit(1));
    expect($sql)->toContain('index_a,index_b');
});

// ---------------------------------------------------------------------------
// expression (script fields)
// ---------------------------------------------------------------------------

it('expression stores the script field and is chainable', function () {
    $b = makeBuilder();
    $result = $b->expression('rank', '(score * 2)');
    expect($result)->toBe($b);
    $ref  = new ReflectionClass($b);
    $prop = $ref->getProperty('scriptFields');
    $prop->setAccessible(true);
    $fields = $prop->getValue($b);
    expect($fields)->toHaveKey('rank');
    expect($fields['rank'])->toBe('(score * 2)');
});

// ---------------------------------------------------------------------------
// Invalid operator
// ---------------------------------------------------------------------------

it('throws InvalidArgumentException for unsupported operator', function () {
    makeBuilder()->where('field', 'LIKE', 'value');
})->throws(\InvalidArgumentException::class, 'Unsupported operator [LIKE]');
