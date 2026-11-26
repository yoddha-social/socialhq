<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;

/*
 * QA 2026-11-18, item 13: the plan said 9:00 (Sydney) and the composer
 * 4:45 PM (Kathmandu, the channel's zone). The item page says both.
 */

test('the item page gives the time where the audience is and in the channel\'s zone', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'Asia/Kathmandu']);
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id, 'month' => $month->toDateString()]);
    $item = PlanItem::factory()->create([
        'plan_id' => $plan->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform->value,
        'content_type' => ContentType::LinkedInPost,
        'creative' => CreativeKind::None,
        'copy_status' => CopyStatus::Approved,
        'scheduled_on' => $month->addDays(14)->toDateString(),
        'suggested_time' => '09:00',
        'suggested_timezone' => 'Australia/Sydney',
    ]);
    ItemVersion::factory()->for($item, 'item')->create(['caption' => 'Happy Dashain']);
    $this->actingAs($user->fresh());

    $page = visit(route('socialhq.plan.items.show', $item->id))->resize(1440, 900);
    waitFor($page, '@item-posting-time');
    waitFor($page, '@item-create-post-time');

    $summary = (string) $page->script('document.querySelector(\'[data-testid="item-posting-time"]\').textContent.trim()');
    $hint = (string) $page->script('document.querySelector(\'[data-testid="item-create-post-time"]\').textContent.trim()');

    expect($summary)->toContain('Sydney')->toContain('Kathmandu')
        ->and($hint)->toContain('Sydney')->toContain('Kathmandu');
    $page->assertNoJavaScriptErrors();
});
