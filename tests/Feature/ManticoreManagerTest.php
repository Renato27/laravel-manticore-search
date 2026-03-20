<?php

use ManticoreLaravel\Support\ManticoreManager;
use Manticoresearch\Client;

it('reuses cached client for same connection', function () {
    $manager = app(ManticoreManager::class);

    $first = $manager->client('default');
    $second = $manager->client('default');

    expect($first)->toBeInstanceOf(Client::class)
        ->and($second)->toBe($first);
});

it('returns different clients for different named connections', function () {
    $manager = app(ManticoreManager::class);

    $defaultClient = $manager->client('default');
    $analyticsClient = $manager->client('analytics');

    expect($defaultClient)->not->toBe($analyticsClient);
});

it('exposes configured connection names', function () {
    $manager = app(ManticoreManager::class);

    expect($manager->connectionNames())
        ->toContain('default', 'analytics');
});

it('forgets one cached connection client without clearing others', function () {
    $manager = app(ManticoreManager::class);

    $defaultBefore = $manager->client('default');
    $analyticsBefore = $manager->client('analytics');

    $manager->forgetClient('default');

    $defaultAfter = $manager->client('default');
    $analyticsAfter = $manager->client('analytics');

    expect($defaultAfter)->not->toBe($defaultBefore)
        ->and($analyticsAfter)->toBe($analyticsBefore);
});
