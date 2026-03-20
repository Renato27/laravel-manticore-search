<?php

use Orchestra\Testbench\TestCase;
use ManticoreLaravel\ManticoreServiceProvider;
use ManticoreLaravel\Support\ManticoreConnectionResolver;

/**
 * Unit tests for the centralized connection config resolver.
 *
 * No network connection is required — the resolver only reads config.
 */
class ManticoreConnectionResolverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ManticoreServiceProvider::class];
    }

    public function test_resolves_explicit_named_connection(): void
    {
        config([
            'manticore.connections' => [
                'replica' => [
                    'host'        => '10.0.0.2',
                    'port'        => 9312,
                    'username'    => null,
                    'password'    => null,
                    'transport'   => 'Http',
                    'timeout'     => 10,
                    'persistent'  => false,
                    'max_matches' => 2000,
                ],
            ],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('replica');

        $this->assertSame('10.0.0.2', $config['host']);
        $this->assertSame(2000, $config['max_matches']);
    }

    public function test_explicit_connection_name_takes_priority_over_default(): void
    {
        config([
            'manticore.default' => 'default',
            'manticore.connections' => [
                'default' => [
                    'host'        => 'default-host',
                    'max_matches' => 1000,
                ],
                'analytics' => [
                    'host'        => 'analytics-host',
                    'max_matches' => 9999,
                ],
            ],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('analytics');

        $this->assertSame('analytics-host', $config['host']);
        $this->assertSame(9999, $config['max_matches']);
    }

    public function test_resolves_default_named_connection(): void
    {
        config([
            'manticore.default' => 'default',
            'manticore.connections' => [
                'default' => [
                    'host'        => '192.168.1.1',
                    'port'        => 9312,
                    'username'    => null,
                    'password'    => null,
                    'transport'   => 'Http',
                    'timeout'     => 5,
                    'persistent'  => false,
                    'max_matches' => 5000,
                ],
            ],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        $this->assertSame('192.168.1.1', $config['host']);
        $this->assertSame(5000, $config['max_matches']);
    }

    public function test_resolves_non_default_named_connection_when_set_as_default(): void
    {
        config([
            'manticore.default' => 'primary',
            'manticore.connections' => [
                'primary' => [
                    'host'        => 'primary-host',
                    'max_matches' => 7500,
                ],
            ],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('primary');

        $this->assertSame('primary-host', $config['host']);
        $this->assertSame(7500, $config['max_matches']);
    }

    public function test_connections_block_takes_priority_over_legacy_flat_config(): void
    {
        config([
            'manticore.default' => 'default',
            'manticore.connections' => [
                'default' => [
                    'host'        => 'connections-host',
                    'max_matches' => 7777,
                ],
            ],
            'manticore.host'        => 'legacy-host',
            'manticore.max_matches' => 1000,
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        $this->assertSame('connections-host', $config['host']);
        $this->assertSame(7777, $config['max_matches']);
    }

    public function test_resolves_legacy_flat_config_when_no_connections_key(): void
    {
        config([
            'manticore' => [
                'host'        => '127.0.0.1',
                'port'        => 9312,
                'username'    => null,
                'password'    => null,
                'transport'   => 'Http',
                'timeout'     => 5,
                'persistent'  => false,
                'max_matches' => 3000,
            ],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(3000, $config['max_matches']);
    }

    public function test_resolves_legacy_flat_config_when_connections_is_empty(): void
    {
        config([
            'manticore.host'        => '10.1.1.1',
            'manticore.max_matches' => 500,
            'manticore.connections' => [],
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        $this->assertSame('10.1.1.1', $config['host']);
        $this->assertSame(500, $config['max_matches']);
    }

    public function test_normalized_config_always_contains_all_required_keys(): void
    {
        config([
            'manticore.connections' => [
                'default' => ['host' => '127.0.0.1'], 
            ],
            'manticore.default' => 'default',
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        foreach (['host', 'port', 'username', 'password', 'transport', 'timeout', 'persistent', 'max_matches'] as $key) {
            $this->assertArrayHasKey($key, $config, "Resolved config is missing key [{$key}]");
        }
    }

    public function test_normalize_fills_in_default_values_for_missing_keys(): void
    {
        config([
            'manticore.connections' => [
                'default' => ['host' => '127.0.0.1'],
            ],
            'manticore.default' => 'default',
        ]);

        $config = app(ManticoreConnectionResolver::class)->resolve('default');

        $this->assertSame(9312,    $config['port']);
        $this->assertSame(null,    $config['username']);
        $this->assertSame(null,    $config['password']);
        $this->assertSame('Http',  $config['transport']);
        $this->assertSame(5,       $config['timeout']);
        $this->assertSame(false,   $config['persistent']);
        $this->assertSame(1000,    $config['max_matches']);
    }

    public function test_throws_invalid_argument_exception_for_undefined_connection(): void
    {
        config([
            'manticore.connections' => [
                'default' => ['host' => '127.0.0.1'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Manticore connection [nonexistent] is not defined.');

        app(ManticoreConnectionResolver::class)->resolve('nonexistent');
    }

    public function test_throws_exception_when_connections_block_is_empty_and_name_is_given(): void
    {
        config([
            'manticore.connections' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Manticore connection [missing] is not defined.');

        app(ManticoreConnectionResolver::class)->resolve('missing');
    }
}
