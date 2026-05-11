<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Integration', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeValidSql', function () {
    return $this->toBeString()
        ->toContain('SELECT')
        ->toContain('FROM');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function makeBuilder(?string $index = 'test_index', array $fillable = ['id', 'title', 'status', 'score', 'created_at']): \ManticoreLaravel\Builder\ManticoreBuilder
{
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        protected $guarded = [];
        public $fillable = [];
        public string $tableName = 'test_index';
        public function searchableAs(): string { return $this->tableName; }
    };
    $model->fillable = $fillable;
    $model->tableName = $index ?? 'test_index';
    return new \ManticoreLaravel\Builder\ManticoreBuilder($model);
}
