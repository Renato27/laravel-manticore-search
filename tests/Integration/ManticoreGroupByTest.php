<?php

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

class GroupByTestModel extends Model
{
    protected $guarded  = [];
    protected $fillable = ['id', 'title', 'status', 'score', 'category'];

    public function searchableAs(): string
    {
        return 'laravel_manticore_test';
    }
}

function groupByBuilder(): ManticoreBuilder
{
    return new ManticoreBuilder(new GroupByTestModel());
}

it('group by produces aggregated results', function () {
    $results = groupByBuilder()
        ->select(['status', 'COUNT(*) as total'])
        ->groupBy('status')
        ->limit(10)
        ->get();

    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
})->group('integration');

it('having filters grouped results', function () {
    $results = groupByBuilder()
        ->select(['status', 'COUNT(*) as total'])
        ->groupBy('status')
        ->having('COUNT(*) >= 1')
        ->limit(10)
        ->get();

    foreach ($results as $result) {
        expect((int) $result->total)->toBeGreaterThanOrEqual(1);
    }
})->group('integration');

it('whereRaw works in group by query', function () {
    $results = groupByBuilder()
        ->select(['status', 'COUNT(*) as total'])
        ->groupBy('status')
        ->whereRaw('score > 0')
        ->limit(10)
        ->get();

    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
})->group('integration');
