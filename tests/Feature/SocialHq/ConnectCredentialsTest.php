<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config([
        'socialhq.channels.require_credentials' => true,
        'services.x.client_id' => null,
        'services.x.client_secret' => null,
        'services.tiktok.client_id' => null,
        'services.facebook.client_id' => 'facebook-id',
        'services.facebook.client_secret' => 'facebook-secret',
        'services.instagram.client_id' => null,
        'trypost.platforms.telegram.bot_token' => null,
    ]);
});

test('networks without the server\'s credentials are not offered', function () {
    $options = collect(Platform::connectableOptions())->keyBy('value');

    expect($options->has(Platform::X->value))->toBeFalse()
        ->and($options->has(Platform::TikTok->value))->toBeFalse()
        ->and($options->has(Platform::Telegram->value))->toBeFalse()
        ->and($options->has(Platform::Facebook->value))->toBeTrue()
        ->and($options->has(Platform::Bluesky->value))->toBeTrue()
        ->and($options->has(Platform::Mastodon->value))->toBeTrue();
});

test('instagram offers only the connect methods the server can start', function () {
    $instagram = collect(Platform::connectableOptions())->firstWhere('value', Platform::Instagram->value);

    expect(data_get($instagram, 'connect_methods'))->toBe([Platform::InstagramFacebook->value]);
});

test('a disabled network stays hidden even with credentials', function () {
    config(['trypost.platforms.facebook.enabled' => false]);

    expect(Platform::Facebook->isConnectable())->toBeFalse();
});

test('the connect route of a network without credentials refuses instead of redirecting to the network', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user->fresh())
        ->get(route('app.social.x.connect'))
        ->assertForbidden();
});

test('the check can be switched off', function () {
    config(['socialhq.channels.require_credentials' => false]);

    expect(Platform::X->isConnectable())->toBeTrue();
});
