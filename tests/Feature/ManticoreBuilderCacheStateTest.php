<?php

use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\Builder\ManticoreBuilder;

class PestBuilderCacheTestModel extends Model
{
    protected $guarded = [];

    public function searchableAs(): array
    {
        return ['initial_index'];
    }
}

it('flushes resolved connection state when switching connection', function () {
    $builder = new ManticoreBuilder(new PestBuilderCacheTestModel());

    $abstract = new ReflectionClass($builder);
    $resolveConfig = $abstract->getMethod('resolveConnectionConfig');
    $resolveConfig->setAccessible(true);

    $first = $resolveConfig->invoke($builder);

    $builder->usingConnection('analytics');

    $second = $resolveConfig->invoke($builder);

    expect($first['host'])->toBe('127.0.0.1')
        ->and($second['host'])->toBe('10.0.0.2');
});

it('flushes resolved index state when overriding index', function () {
    $builder = new ManticoreBuilder(new PestBuilderCacheTestModel());

    $abstract = new ReflectionClass($builder);
    $resolveIndex = $abstract->getMethod('resolveIndexName');
    $resolveIndex->setAccessible(true);

    $before = $resolveIndex->invoke($builder);

    $builder->useIndex('override_index');

    $after = $resolveIndex->invoke($builder);

    expect($before)->toBe('initial_index')
        ->and($after)->toBe('override_index');
});
