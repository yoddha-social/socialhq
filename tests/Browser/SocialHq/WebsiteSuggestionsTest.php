<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Website\CrawlStatus;
use App\SocialHq\Knowledge\Website\WebsiteCrawl;

/*
 * "Read our website" keeps the suggestions still waiting for a decision in
 * view and folds the accepted and skipped ones under one closed row, so a
 * fully reviewed run no longer fills the Sources tab.
 */

test('reviewed website suggestions fold under one closed row that opens on click', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $crawl = WebsiteCrawl::query()->create([
        'workspace_id' => $workspace->id,
        'url' => 'https://acme.example',
        'status' => CrawlStatus::Ready,
        'pages_read' => 25,
        'requested_by' => $user->id,
        'finished_at' => now(),
        'suggestions' => [
            ['key' => 'profile-about', 'type' => 'profile', 'field' => 'about', 'value' => 'Acme Bags hand-stitches trekking bags in Kathmandu.', 'status' => 'accepted'],
            ['key' => 'profile-audience', 'type' => 'profile', 'field' => 'audience', 'value' => 'Hikers and trekking guides.', 'status' => 'open'],
            ['key' => 'entry-1', 'type' => 'entry', 'kind' => 'product', 'title' => 'Trailblazer 40', 'body' => 'A waterproof trekking bag.', 'status' => 'skipped'],
        ],
    ]);
    $this->actingAs($user->fresh());

    $page = visit(route('socialhq.brand.index', ['tab' => 'sources']))->resize(1440, 900);

    // The open suggestion is in view; the two decided ones are behind a closed row.
    waitFor($page, '@socialhq-website-suggestion-profile-audience');
    waitForState($page, '@socialhq-website-reviewed-toggle', "element.getAttribute('aria-expanded') === 'false'");
    $page->assertSeeIn('@socialhq-website-reviewed-toggle', __('socialhq_knowledge.website.review.reviewed', ['count' => 2]))
        ->assertMissing('@socialhq-website-suggestion-profile-about')
        ->assertMissing('@socialhq-website-suggestion-entry-1');

    // Skipping the last open one moves it under the row, which stays closed.
    $page->click('@socialhq-website-suggestion-skip-profile-audience');
    waitForGone($page, '@socialhq-website-suggestion-profile-audience');
    waitForText($page, '@socialhq-website-reviewed-toggle', __('socialhq_knowledge.website.review.reviewed', ['count' => 3]));
    expect(collect($crawl->fresh()->suggestions)->firstWhere('key', 'profile-audience')['status'])->toBe('skipped');
    $page->assertSee(__('socialhq_knowledge.website.review.done'))
        ->assertMissing('@socialhq-website-suggestion-profile-about')
        ->assertMissing('@socialhq-website-suggestion-entry-1');

    // Opening the row shows every reviewed card with its decision.
    $page->click('@socialhq-website-reviewed-toggle');
    waitForState($page, '@socialhq-website-reviewed-toggle', "element.getAttribute('aria-expanded') === 'true'");
    waitFor($page, '@socialhq-website-reviewed @socialhq-website-suggestion-profile-about');
    waitFor($page, '@socialhq-website-reviewed @socialhq-website-suggestion-entry-1');
    waitFor($page, '@socialhq-website-reviewed @socialhq-website-suggestion-profile-audience');
    $page->assertSeeIn('@socialhq-website-suggestion-status-profile-about', __('socialhq_knowledge.website.review.accepted'))
        ->assertSeeIn('@socialhq-website-suggestion-status-entry-1', __('socialhq_knowledge.website.review.skipped'))
        ->assertNoJavaScriptErrors();
});

test('website keyword suggestions show in their groups with tags and why; an edited one goes to the catalogue with its tags', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    BrandProfile::query()->create(['workspace_id' => $workspace->id, 'pillars' => ['Gear guides']]);
    $keyword = fn (string $key, string $text, string $group, array $extra = []): array => [
        'key' => $key, 'type' => 'keyword', 'text' => $text, 'main' => false, 'main_full' => false, 'group' => $group,
        'tags' => [], 'pages' => 2, 'page_titles' => ['Products', 'Home'], 'sample' => '', 'verdict' => 'good', 'reason' => '', 'status' => 'open', ...$extra,
    ];

    $crawl = WebsiteCrawl::query()->create([
        'workspace_id' => $workspace->id,
        'url' => 'https://acme.example',
        'status' => CrawlStatus::Ready,
        'pages_read' => 4,
        'requested_by' => $user->id,
        'finished_at' => now(),
        'suggestions' => [
            $keyword('keyword-1', 'waterproof trekking bag', 'features', [
                'main' => true,
                'tags' => [['kind' => 'pillar', 'value' => 'Gear guides', 'entry_id' => null, 'from' => null, 'to' => null, 'label' => 'Gear guides']],
                'sample' => 'A waterproof trekking bag for the monsoon trail.',
            ]),
            $keyword('keyword-2', 'trekking gear nepal', 'seasonal'),
        ],
    ]);
    $this->actingAs($user->fresh());

    $page = visit(route('socialhq.brand.index', ['tab' => 'sources']))->resize(1440, 900);
    waitFor($page, '@socialhq-website-keywords-features @socialhq-website-suggestion-keyword-1');
    waitFor($page, '@socialhq-website-keywords-seasonal @socialhq-website-suggestion-keyword-2');
    waitFor($page, '@socialhq-website-suggestion-keyword-1 @socialhq-keyword-tag-pillar');
    $page->assertVisible('@socialhq-website-suggestion-main-keyword-1')
        ->assertSeeIn('@socialhq-website-suggestion-why-keyword-1', 'Products, Home');

    $page->click('@socialhq-website-suggestion-edit-keyword-1');
    waitFor($page, '@socialhq-website-suggestion-keyword-keyword-1');
    $page->fill('@socialhq-website-suggestion-keyword-keyword-1', 'waterproof trekking backpack')
        ->click('@socialhq-website-suggestion-accept-keyword-1');
    waitForDatabase($page, fn (): bool => collect(BrandKeywords::catalogue($workspace))->firstWhere('text', 'waterproof trekking backpack') !== null);
    waitForGone($page, '@socialhq-website-keywords-features');

    $saved = collect(BrandKeywords::catalogue($workspace))->firstWhere('text', 'waterproof trekking backpack');
    expect($saved['main'])->toBeTrue()
        ->and(collect($saved['tags'])->pluck('value')->all())->toBe(['Gear guides'])
        ->and(collect($crawl->fresh()->suggestions)->firstWhere('key', 'keyword-1')['status'])->toBe('accepted');
    $page->assertVisible('@socialhq-website-suggestion-keyword-2')
        ->assertNoJavaScriptErrors();
});
