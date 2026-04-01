<?php

use ManticoreLaravel\Support\ManticoreConnectionResolver;

it('includes available connections in undefined connection error', function () {
    $resolver = app(ManticoreConnectionResolver::class);

    $resolver->resolve('missing-connection');
})->throws(
    InvalidArgumentException::class,
    'Available connections: default, analytics.'
);

it('returns normalized typed values for sparse connection config', function () {
    config([
        'manticore.default' => 'sparse',
        'manticore.connections' => [
            'sparse' => [
                'host' => 'localhost',
                'port' => '9312',
                'timeout' => '7',
                'persistent' => 1,
                'max_matches' => '55',
            ],
        ],
    ]);

    $resolver = app(ManticoreConnectionResolver::class);
    $resolved = $resolver->resolve();

    expect($resolved['host'])->toBe('localhost')
        ->and($resolved['port'])->toBeInt()->toBe(9312)
        ->and($resolved['timeout'])->toBeInt()->toBe(7)
        ->and($resolved['persistent'])->toBeTrue()
        ->and($resolved['max_matches'])->toBeInt()->toBe(55)
        ->and($resolved['transport'])->toBe('Http');
});
