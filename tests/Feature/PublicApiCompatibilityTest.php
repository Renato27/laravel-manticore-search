<?php

use Illuminate\Database\Eloquent\Model;
use ManticoreLaravel\Builder\ManticoreBuilder;
use ManticoreLaravel\Facades\Manticore as ManticoreFacade;
use ManticoreLaravel\Support\ManticoreManager;
use ManticoreLaravel\Traits\HasManticoreSearch;

class CompatibleSearchModel extends Model
{
    use HasManticoreSearch;

    protected $guarded = [];

    public function searchableAs(): array
    {
        return ['compat_index'];
    }
}

class MissingSearchableAsModel extends Model
{
    use HasManticoreSearch;

    protected $guarded = [];
}

it('keeps trait based public entry point returning builder', function () {
    $builder = CompatibleSearchModel::manticore();

    expect($builder)
        ->toBeInstanceOf(ManticoreBuilder::class)
        ->and($builder->usingConnection('default'))->toBe($builder)
        ->and($builder->useIndex('compat_index'))->toBe($builder);
});

it('keeps runtime guard when searchableAs is missing', function () {
    MissingSearchableAsModel::manticore();
})->throws(RuntimeException::class, 'must implement the searchableAs() method');

it('keeps manager singleton and container alias stable', function () {
    $managerByClass = app(ManticoreManager::class);
    $managerByAlias = app('manticore');

    expect($managerByClass)
        ->toBeInstanceOf(ManticoreManager::class)
        ->and($managerByAlias)->toBe($managerByClass);
});

it('keeps facade root bound to manager alias', function () {
    $root = ManticoreFacade::getFacadeRoot();

    expect($root)
        ->toBeInstanceOf(ManticoreManager::class)
        ->and($root)->toBe(app('manticore'));
});

it('keeps key builder method signatures backward compatible', function () {
    $reflection = new ReflectionClass(ManticoreBuilder::class);

    $usingConnection = $reflection->getMethod('usingConnection');
    expect($usingConnection->getNumberOfParameters())->toBe(1)
        ->and((string) $usingConnection->getReturnType())->toBe('static');

    $useIndex = $reflection->getMethod('useIndex');
    expect($useIndex->getNumberOfParameters())->toBe(1)
        ->and((string) $useIndex->getReturnType())->toBe('static');

    $rawQuery = $reflection->getMethod('rawQuery');
    expect($rawQuery->getNumberOfParameters())->toBe(2)
        ->and($rawQuery->getParameters()[1]->isOptional())->toBeTrue()
        ->and((string) $rawQuery->getReturnType())->toBe('static');

    $where = $reflection->getMethod('where');
    expect($where->getNumberOfParameters())->toBe(3)
        ->and($where->getParameters()[2]->isOptional())->toBeTrue()
        ->and((string) $where->getReturnType())->toBe('static');
});
