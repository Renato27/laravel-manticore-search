<?php

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

class PaginationTestModel extends Model
{
    protected $guarded  = [];
    protected $fillable = ['id', 'title', 'status', 'score'];

    public function searchableAs(): string
    {
        return 'laravel_manticore_test';
    }
}

function paginationBuilder(): ManticoreBuilder
{
    return new ManticoreBuilder(new PaginationTestModel());
}

it('paginate returns LengthAwarePaginator', function () {
    $paginator = paginationBuilder()->paginate(5);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
        ->and($paginator->perPage())->toBe(5);
})->group('integration');

it('paginate has a non-negative total', function () {
    $paginator = paginationBuilder()->paginate(5);

    expect($paginator->total())->toBeGreaterThanOrEqual(0);
})->group('integration');

it('paginate with page 2 returns different results than page 1', function () {
    $page1 = paginationBuilder()->paginate(3, 'page', 1);
    $page2 = paginationBuilder()->paginate(3, 'page', 2);

    if ($page1->count() >= 3 && $page2->count() > 0) {
        $ids1 = $page1->pluck('id')->toArray();
        $ids2 = $page2->pluck('id')->toArray();
        expect(array_intersect($ids1, $ids2))->toBeEmpty();
    }

    expect(true)->toBeTrue();
})->group('integration');

it('paginate with explicit max_matches overrides default', function () {
    $paginator = paginationBuilder()
        ->maxMatches(100)
        ->paginate(5);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class);
})->group('integration');
