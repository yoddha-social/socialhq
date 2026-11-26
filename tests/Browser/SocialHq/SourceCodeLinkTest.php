<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

/*
 * The AGPL source offer lives in the user menu's Help, never on the sidebar or
 * a public page (audit 2026-11-24, ARC-14). It must stay reachable for
 * everyone signed in.
 */

/**
 * Links to the source repository anywhere on the page.
 */
function sourceLinkCount(mixed $page): int
{
    return (int) $page->script(<<<'JS'
        [...document.querySelectorAll('a[href]')]
            .filter((link) => link.getAttribute('href').includes('github.com/yoddha-social/socialhq'))
            .filter((link) => !link.getAttribute('href').endsWith('/issues'))
            .length
    JS);
}

test('signed-in people find the source code in Help, not on the sidebar', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1440, 900);
    waitFor($page, '@sidebar-workspace-menu');

    expect(sourceLinkCount($page))->toBe(0);

    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@sidebar-support-trigger');
    // The Help item is a Radix sub-trigger, which opens on a pointer move before the click.
    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-testid="sidebar-support-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
        })();
    JS);
    waitFor($page, '@sidebar-support-github');

    $page->assertSeeIn('@sidebar-support-github', __('socialhq.source_code'));
    expect($page->script('document.querySelector(\'[data-testid="sidebar-support-github"]\').getAttribute("href")'))
        ->toContain('github.com/yoddha-social/socialhq');
    $page->assertNoJavaScriptErrors();
});

test('the login page shows no source link', function () {
    $page = visit(route('login'));
    waitFor($page, 'form');

    expect(sourceLinkCount($page))->toBe(0);
    $page->assertNoJavaScriptErrors();
});
