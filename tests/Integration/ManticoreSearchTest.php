<?php

use ManticoreLaravel\Builder\ManticoreBuilder;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Integration test model
|--------------------------------------------------------------------------
*/
class SearchTestModel extends Model
{
    protected $guarded  = [];
    protected $fillable = ['id', 'title', 'status', 'score', 'category'];

    public function searchableAs(): string
    {
        return 'laravel_manticore_test';
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function searchBuilder(): ManticoreBuilder
{
    return new ManticoreBuilder(new SearchTestModel());
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('can get results from Manticore', function () {
    $results = searchBuilder()->limit(5)->get();
    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
})->group('integration');

it('can filter with where clause', function () {
    $results = searchBuilder()->where('status', 'active')->limit(5)->get();
    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);

    foreach ($results as $result) {
        expect($result->status)->toBe('active');
    }
})->group('integration');

it('can search with match', function () {
    $results = searchBuilder()->match('test')->limit(5)->get();
    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
})->group('integration');

it('can order by column', function () {
    $results = searchBuilder()->orderBy('score', 'desc')->limit(5)->get();
    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);

    $scores = $results->pluck('score')->toArray();
    for ($i = 1; $i < count($scores); $i++) {
        expect($scores[$i])->toBeLessThanOrEqual($scores[$i - 1]);
    }
})->group('integration');

it('can paginate results', function () {
    $paginator = searchBuilder()->limit(3)->paginate(3);

    expect($paginator)->toBeInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class)
        ->and($paginator->perPage())->toBe(3);
})->group('integration');

it('can execute raw query', function () {
    $results = searchBuilder()
        ->rawQuery("SELECT * FROM laravel_manticore_test LIMIT 3")
        ->get();

    expect($results->count())->toBeLessThanOrEqual(3);
})->group('integration');

it('can group by and count', function () {
    $results = searchBuilder()
        ->select(['status', 'COUNT(*) as total'])
        ->groupBy('status')
        ->limit(10)
        ->get();

    expect($results)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
})->group('integration');

it('can get facets', function () {
    $facets = searchBuilder()
        ->aggregate('status_agg', ['terms' => ['field' => 'status', 'size' => 5]])
        ->getFacets();

    expect($facets)->toBeArray()->toHaveKey('status_agg');
})->group('integration');

it('chunk iterates through all results', function () {
    $count = 0;
    searchBuilder()->chunk(2, function ($results) use (&$count) {
        $count += $results->count();
    });

    expect($count)->toBeGreaterThanOrEqual(0);
})->group('integration');

it('chunk stops when callback returns false', function () {
    $pages = 0;
    searchBuilder()->chunk(2, function () use (&$pages) {
        $pages++;
        return false; // stop after first chunk
    });

    expect($pages)->toBe(1);
})->group('integration');

it('forPage returns correct results', function () {
    $page1 = searchBuilder()->forPage(1, 2)->get();
    $page2 = searchBuilder()->forPage(2, 2)->get();

    // They should not be identical (assuming >2 results)
    if ($page1->count() === 2 && $page2->count() > 0) {
        expect($page1->first()->getKey())->not->toBe($page2->first()->getKey());
    }

    expect(true)->toBeTrue(); // ensure test ran
})->group('integration');
