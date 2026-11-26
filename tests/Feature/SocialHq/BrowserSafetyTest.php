<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Laravel\Reverb\ConfigApplicationProvider;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Contracts\WebSocketConnection;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\EventHandler;
use Laravel\Reverb\Protocols\Pusher\Server as PusherServer;

/**
 * The browser safety the app sets itself (audit 2026-11-24, SEC-09): the
 * three standard headers on every response, and live updates (Reverb) only
 * for pages of the app's own address. Reverb's control API isn't routed by
 * nginx any more (ContainerRuntimeTest).
 */
function expectSafetyHeaders(TestResponse $response): void
{
    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
}

// The browser enforces them: no other site shows the app in a frame (SAMEORIGIN
// still lets the composer preview a PDF from our own media route), no file is
// read as another type than it says, and a link out carries only our origin.
test('pages, errors, the API and the health check carry the safety headers', function () {
    expectSafetyHeaders($this->get(route('login'))->assertOk());
    // No route has these addresses: a page that doesn't exist, and the health check's unnamed route.
    expectSafetyHeaders($this->get('/no-such-page-'.Str::random(8))->assertNotFound());
    expectSafetyHeaders($this->get('/up')->assertOk());
    expectSafetyHeaders($this->getJson(route('mcp.oauth.authorization-server'))->assertOk());
    expectSafetyHeaders($this->getJson(route('api.posts.index'))->assertUnauthorized());

    $this->actingAs(User::factory()->create());
    expectSafetyHeaders($this->get(route('app.home')));
});

test('a response that sets its own value keeps it', function () {
    Route::get('socialhq-test/no-referrer', fn () => response('ok')->header('Referrer-Policy', 'no-referrer'))->name('socialhq-test.no-referrer');
    Route::getRoutes()->refreshNameLookups();

    $this->get(route('socialhq-test.no-referrer'))
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

/**
 * Opens a live-update connection to Reverb as a browser on the given page
 * origin would, and returns what Reverb sent back.
 *
 * @return list<array<string, mixed>>
 */
function openReverbConnection(?string $origin): array
{
    // The test env has no Reverb credentials; the allowed origins are the configured ones.
    $app = (new ConfigApplicationProvider(collect([[...config('reverb.apps.apps.0'), 'app_id' => '1001', 'key' => 'test-key', 'secret' => 'test-secret']])))->findById('1001');

    $connection = new class(Mockery::mock(WebSocketConnection::class), $app, $origin) extends Connection
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];

        public function identifier(): string
        {
            return 'test';
        }

        public function id(): string
        {
            return 'test.1';
        }

        public function send(string $message): void
        {
            $this->sent[] = json_decode($message, true);
        }

        public function control(string $type = 'ping'): void {}

        public function terminate(): void {}
    };

    $handler = Mockery::mock(EventHandler::class);
    $handler->shouldReceive('handle')->andReturnUsing(fn (Connection $connection, string $event) => $connection->send(json_encode(['event' => $event])));

    (new PusherServer(Mockery::mock(ChannelManager::class), $handler))->open($connection);

    return $connection->sent;
}

/**
 * Whether Reverb refused the connection because of its page's origin.
 *
 * @param  list<array<string, mixed>>  $sent
 */
function refusedForOrigin(array $sent): bool
{
    return data_get($sent, '0.event') === 'pusher:error'
        && data_get(json_decode((string) data_get($sent, '0.data'), true), 'code') === 4009;
}

test('live updates connect only from pages of the app\'s own address', function () {
    $appHost = Uri::of((string) config('app.url'))->host();

    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe([$appHost])
        ->and(data_get(openReverbConnection((string) config('app.url')), '0.event'))->toBe('pusher:connection_established')
        ->and(refusedForOrigin(openReverbConnection('https://attacker.example')))->toBeTrue()
        ->and(refusedForOrigin(openReverbConnection("https://{$appHost}.attacker.example")))->toBeTrue()
        ->and(refusedForOrigin(openReverbConnection(null)))->toBeTrue();
});

test('more hosts can be allowed, with wildcards', function () {
    config()->set('reverb.apps.apps.0.allowed_origins', ['social.example.com', '*.trycloudflare.com']);

    expect(data_get(openReverbConnection('https://social.example.com'), '0.event'))->toBe('pusher:connection_established')
        ->and(data_get(openReverbConnection('https://quiet-river.trycloudflare.com'), '0.event'))->toBe('pusher:connection_established')
        ->and(refusedForOrigin(openReverbConnection('https://attacker.example')))->toBeTrue();
});
