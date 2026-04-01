<?php

use ManticoreLaravel\Facades\Manticore;
use Manticoresearch\Client;
use Manticoresearch\Table;

it('resolves config through facade', function () {
    $config = Manticore::resolveConfig('default');

    expect($config)
        ->toBeArray()
        ->and($config['host'])->toBe('127.0.0.1');
});

it('resolves cached client through facade', function () {
    $first = Manticore::client('default');
    $second = Manticore::client('default');

    expect($first)->toBeInstanceOf(Client::class)
        ->and($second)->toBe($first);
});

it('creates table instance through facade', function () {
    $table = Manticore::table('companies_index', 'default');

    expect($table)->toBeInstanceOf(Table::class);
});
