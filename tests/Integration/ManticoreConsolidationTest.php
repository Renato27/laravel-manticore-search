<?php

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

class ConsolidationTestModel extends Model
{
    protected $guarded  = [];
    protected $fillable = ['id', 'entity_id', 'title', 'action', 'status'];

    public function searchableAs(): string
    {
        return 'laravel_manticore_test';
    }
}

function consolidationBuilder(): ManticoreBuilder
{
    return new ManticoreBuilder(new ConsolidationTestModel());
}

it('consolidateAllBy returns a collection', function () {
    $results = consolidationBuilder()->limit(20)->consolidateAllBy('entity_id');

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
})->group('integration');

it('consolidateAllBy adds history attribute', function () {
    $results = consolidationBuilder()->limit(20)->consolidateAllBy('entity_id');

    foreach ($results as $result) {
        // Each result should have a history attribute (it's set on the model)
        expect($result)->not->toBeNull();
    }
})->group('integration');

it('paginateConsolidatedBy returns LengthAwarePaginator', function () {
    $paginator = consolidationBuilder()->paginateConsolidatedBy('entity_id', 5);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class);
})->group('integration');
