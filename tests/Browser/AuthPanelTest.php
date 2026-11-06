<?php

declare(strict_types=1);

test('the Social HQ panel lists what the app does', function () {
    config(['trypost.self_hosted' => false]);

    $page = visit(route('login'));

    $page->assertVisible('@auth-panel-points')
        ->assertSeeIn('@auth-panel-points', 'Plan each month around your campaigns and festivals.')
        ->assertSeeIn('@auth-panel-points', 'Write posts with AI that knows your brand.')
        ->assertSeeIn('@auth-panel-points', 'Review every post before it goes out.')
        ->assertNoJavaScriptErrors();
});

test('the panel sits beside login and register only', function (string $route, bool $hasPanel) {
    config(['trypost.self_hosted' => false]);

    $page = visit(route($route));

    $page->assertVisible('@auth-logo');

    $hasPanel
        ? $page->assertPresent('@auth-panel-points')
        : $page->assertMissing('@auth-panel-points');

    $page->assertNoJavaScriptErrors();
})->with([
    ['login', true],
    ['password.request', false],
]);

test('the auth column keeps a 16px gutter without overflow on phones', function (string $route) {
    config(['trypost.self_hosted' => false]);

    $page = visit(route($route))->resize(390, 844);
    $page->assertVisible('@auth-logo');

    $layout = $page->script(<<<'JS'
        (() => {
            const email = document.querySelector('#email').getBoundingClientRect();
            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                left: Math.round(email.left),
                right: Math.round(window.innerWidth - email.right),
                height: Math.round(email.height),
            };
        })()
    JS);

    expect($layout)->toBe(['overflow' => false, 'left' => 16, 'right' => 16, 'height' => 32]);
})->with(['login']);
