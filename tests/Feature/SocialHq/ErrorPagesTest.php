<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Route::middleware('web')->group(function (): void {
        Route::get('socialhq-test/forbidden', fn () => abort(Response::HTTP_FORBIDDEN, 'secret detail'));
        Route::get('socialhq-test/broken', fn () => throw new RuntimeException('secret detail'));
        Route::get('socialhq-test/maintenance', fn () => abort(Response::HTTP_SERVICE_UNAVAILABLE));
    });
});

test('a forbidden page renders the app error page without the message', function () {
    $this->actingAs(User::factory()->create(['theme' => 'dark']))
        ->get('socialhq-test/forbidden')
        ->assertForbidden()
        ->assertDontSee('secret detail')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('socialhq/Error')
            ->where('status', Response::HTTP_FORBIDDEN)
            ->where('auth.user.theme', 'dark'));
});

test('an unknown address renders the app error page as a 404', function () {
    $this->get('socialhq-test/nothing-here')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('socialhq/Error')
            ->where('status', Response::HTTP_NOT_FOUND));
});

test('a server error renders the app error page only when debug is off', function () {
    config(['app.debug' => false]);

    $this->get('socialhq-test/broken')
        ->assertInternalServerError()
        ->assertDontSee('secret detail')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('socialhq/Error')
            ->where('status', Response::HTTP_INTERNAL_SERVER_ERROR));

    config(['app.debug' => true]);

    $inertiaPage = substr((string) json_encode(['component' => 'socialhq/Error']), 1, -1);

    expect($this->get('socialhq-test/broken')->assertInternalServerError()->getContent())->not->toContain($inertiaPage);
});

test('maintenance renders the app error page as a 503', function () {
    $this->get('socialhq-test/maintenance')
        ->assertServiceUnavailable()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('socialhq/Error')->where('status', Response::HTTP_SERVICE_UNAVAILABLE));
});

test('json and api errors are left as they were', function () {
    $this->getJson('socialhq-test/forbidden')
        ->assertForbidden()
        ->assertJsonStructure(['message']);

    $this->getJson('api/socialhq-test-missing')
        ->assertNotFound()
        ->assertJsonStructure(['message']);

    expect($this->get('api/socialhq-test-missing')->assertNotFound()->getContent())->not->toContain('socialhq\\/Error');
});

test('an inertia visit to a forbidden page gets the error page as an inertia response', function () {
    $this->actingAs(User::factory()->create());
    $version = (string) $this->get('socialhq-test/forbidden')->viewData('page')['version'];

    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'])
        ->get('socialhq-test/forbidden')
        ->assertForbidden()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'socialhq/Error')
        ->assertJsonPath('props.status', Response::HTTP_FORBIDDEN);
});
