<?php

use ManticoreLaravel\Support\ManticoreManager;
use ManticoreLaravel\Support\ManticoreClientFactory;
use ManticoreLaravel\Support\ManticoreConnectionResolver;
use ManticoreLaravel\Builder\Utils\Utf8SafeClient;
use Illuminate\Config\Repository;
use Manticoresearch\Table;

function makeManager(): ManticoreManager
{
    $config   = new Repository([
        'manticore' => [
            'default'     => 'default',
            'connections' => [
                'default' => [
                    'host'        => '127.0.0.1',
                    'port'        => 9308,
                    'username'    => null,
                    'password'    => null,
                    'transport'   => 'Http',
                    'timeout'     => 5,
                    'persistent'  => false,
                    'max_matches' => 1000,
                ],
            ],
        ],
    ]);

    $resolver = new ManticoreConnectionResolver($config);
    $factory  = new ManticoreClientFactory();

    return new ManticoreManager($resolver, $factory);
}

it('resolves default connection config', function () {
    $manager = makeManager();
    $config  = $manager->resolveConfig();

    expect($config['host'])->toBe('127.0.0.1')
        ->and($config['port'])->toBe(9308);
});

it('returns a Utf8SafeClient for default connection', function () {
    $manager = makeManager();
    $client  = $manager->client();

    expect($client)->toBeInstanceOf(Utf8SafeClient::class);
});

it('caches client instances per connection', function () {
    $manager = makeManager();

    $first  = $manager->client();
    $second = $manager->client();

    expect($first)->toBe($second);
});

it('forgetClient clears the default client cache', function () {
    $manager = makeManager();
    $first   = $manager->client();
    $manager->forgetClient();
    $second  = $manager->client();

    expect($first)->not->toBe($second);
});

it('returns all connection names', function () {
    $manager = makeManager();

    expect($manager->connectionNames())->toContain('default');
});

it('creates a Table instance for given index', function () {
    $manager = makeManager();
    $table   = $manager->table('my_index');

    expect($table)->toBeInstanceOf(Table::class);
});
