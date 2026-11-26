<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\KnowledgeEntry;

/*
 * Brand knowledge's status light turns green only once the steps the AI builds
 * on are done, not as soon as the profile is written (audit 2026-11-24, UX-05).
 * Until then it counts the setup and links to the next step.
 */

test('a written profile alone isn\'t "looking good": the card names the next step and opens it', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    BrandProfile::query()->create([
        'workspace_id' => $workspace->id,
        'about' => 'Himal Brew makes cold brew in Kathmandu.',
        'audience' => 'Coffee lovers in Kathmandu',
        'voice' => 'Warm and plain.',
        'pillars' => ['Behind the scenes'],
        'cadence' => [$channel->id => 3],
    ]);
    $this->actingAs($user->fresh());

    // Profile and strategy done; no knowledge and no sources yet.
    $page = visit(route('socialhq.brand.index'))->resize(1440, 900);
    waitForState($page, '@socialhq-brand-health-light', "element.dataset.state === 'setup'");

    $page->assertSeeIn('@socialhq-brand-health-title', __('socialhq.brand.overview.health.setup', ['done' => 2, 'total' => 6]))
        ->assertSeeIn('@socialhq-brand-health-hint', __('socialhq.brand.overview.health.next.knowledge'))
        ->assertDontSeeIn('@socialhq-brand-health', __('socialhq.brand.overview.health.ready'));

    $page->click('@socialhq-brand-health-next');
    waitForState($page, '@socialhq-brand-tab-knowledge', "element.getAttribute('data-state') === 'active'");

    // With knowledge and a source the light is green; the look and SEO keywords are extras.
    KnowledgeEntry::query()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'product',
        'title' => 'Cold brew bottle',
        'body' => '500 ml, brewed for 18 hours.',
        'use_in_ai' => true,
    ]);
    CalendarImport::factory()->create(['workspace_id' => $workspace->id]);

    $page = visit(route('socialhq.brand.index'))->resize(1440, 900);
    waitForState($page, '@socialhq-brand-health-light', "element.dataset.state === 'ready'");

    $page->assertSeeIn('@socialhq-brand-health-title', __('socialhq.brand.overview.health.ready'))
        ->assertSeeIn('@socialhq-brand-health-hint', trans_choice('socialhq.brand.overview.health.ready_hint_optional', 2, ['count' => 2]))
        ->assertMissing('@socialhq-brand-health-next')
        ->assertNoJavaScriptErrors();
});
