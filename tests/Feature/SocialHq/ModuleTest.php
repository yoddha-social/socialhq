<?php

declare(strict_types=1);

use App\SocialHq\SocialHqServiceProvider;

test('the Social HQ module is registered', function () {
    expect(app()->getProviders(SocialHqServiceProvider::class))->not->toBeEmpty();
});

test('Social HQ migrations are loaded from their own folder', function () {
    expect(app('migrator')->paths())->toContain(database_path('migrations/socialhq'));
});

test('the source code link points at the fork', function () {
    expect(config('socialhq.source_url'))->toStartWith('https://github.com/');
});
