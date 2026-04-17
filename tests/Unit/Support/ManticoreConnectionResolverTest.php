<?php

use ManticoreLaravel\Support\ManticoreConnectionResolver;
use ManticoreLaravel\Exceptions\ManticoreConnectionException;
use Illuminate\Config\Repository;

function makeResolver(array $config): ManticoreConnectionResolver
{
    return new ManticoreConnectionResolver(new Repository($config));
}

// ---------------------------------------------------------------------------
// Basic resolution
// ---------------------------------------------------------------------------

it('resolves default connection from connections array', function () {
    $resolver = makeResolver([
        'manticore' => [
            'default'     => 'default',
            'connections' => [
                'default' => ['host' => '192.168.1.1', 'port' => 9308],
            ],
        ],
    ]);

    $config = $resolver->resolve();

    expect($config['host'])->toBe('192.168.1.1')
        ->and($config['port'])->toBe(9308);
});

it('resolves named connection explicitly', function () {
    $resolver = makeResolver([
        'manticore' => [
            'default'     => 'default',
            'connections' => [
                'default'   => ['host' => '127.0.0.1', 'port' => 9308],
                'analytics' => ['host' => '10.0.0.2',  'port' => 9308],
            ],
        ],
    ]);

    $config = $resolver->resolve('analytics');

    expect($config['host'])->toBe('10.0.0.2');
});

it('falls back to legacy flat config when no connections defined', function () {
    $resolver = makeResolver([
        'manticore' => [
            'host' => 'legacy.host',
            'port' => 9312,
        ],
    ]);

    $config = $resolver->resolve();

    expect($config['host'])->toBe('legacy.host')
        ->and($config['port'])->toBe(9312);
});

// ---------------------------------------------------------------------------
// Normalization
// ---------------------------------------------------------------------------

it('normalizes all fields with correct types', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => [
                'default' => [
                    'host'        => '127.0.0.1',
                    'port'        => '9308',
                    'transport'   => 'Http',
                    'timeout'     => '10',
                    'persistent'  => '1',
                    'max_matches' => '2000',
                ],
            ],
        ],
    ]);

    $config = $resolver->resolve();

    expect($config['port'])->toBeInt()->toBe(9308)
        ->and($config['timeout'])->toBeInt()->toBe(10)
        ->and($config['persistent'])->toBeBool()->toBeTrue()
        ->and($config['max_matches'])->toBeInt()->toBe(2000)
        ->and($config['username'])->toBeNull()
        ->and($config['password'])->toBeNull();
});

it('fills in safe defaults for missing keys', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => ['default' => []],
        ],
    ]);

    $config = $resolver->resolve();

    expect($config['host'])->toBe('127.0.0.1')
        ->and($config['port'])->toBe(9312)
        ->and($config['transport'])->toBe('Http')
        ->and($config['timeout'])->toBe(5)
        ->and($config['persistent'])->toBeFalse()
        ->and($config['max_matches'])->toBe(1000);
});

// ---------------------------------------------------------------------------
// Error cases
// ---------------------------------------------------------------------------

it('throws ManticoreConnectionException for unknown connection name', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => ['default' => ['host' => '127.0.0.1']],
        ],
    ]);

    $resolver->resolve('nonexistent');
})->throws(ManticoreConnectionException::class);

it('exception message lists available connections', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => [
                'primary'   => ['host' => '127.0.0.1'],
                'secondary' => ['host' => '10.0.0.1'],
            ],
        ],
    ]);

    try {
        $resolver->resolve('missing');
    } catch (ManticoreConnectionException $e) {
        expect($e->getMessage())->toContain('primary')->toContain('secondary');
    }
});

it('throws ManticoreConnectionException when connection config is not array', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => ['default' => 'not-an-array'],
        ],
    ]);

    $resolver->resolve('default');
})->throws(ManticoreConnectionException::class);

// ---------------------------------------------------------------------------
// availableConnections
// ---------------------------------------------------------------------------

it('returns available connection names', function () {
    $resolver = makeResolver([
        'manticore' => [
            'connections' => [
                'primary'   => [],
                'secondary' => [],
            ],
        ],
    ]);

    expect($resolver->availableConnections())->toBe(['primary', 'secondary']);
});

it('returns empty array when no connections configured', function () {
    $resolver = makeResolver(['manticore' => []]);

    expect($resolver->availableConnections())->toBe([]);
});
