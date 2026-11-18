<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Usage\AiUsageLog;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use App\SocialHq\Companies\Company;
use Carbon\CarbonImmutable;

$usageOwner = function (): User {
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    Company::factory()->create(['account_id' => $owner->account_id]);

    return $owner->fresh();
};

test('today and days with problems open with their rows; other days are one line', function () use ($usageOwner) {
    $owner = $usageOwner();
    $today = CarbonImmutable::now('UTC');
    $quiet = $today->subDays(2)->setTime(12, 0);
    $troubled = $today->subDays(4)->setTime(12, 0);
    $answered = AiUsageLog::factory()->create(['account_id' => $owner->account_id, 'created_at' => $today]);
    AiUsageLog::factory()->create(['account_id' => $owner->account_id, 'created_at' => $quiet]);
    $failed = AiUsageLog::factory()->status(AiUsageStatus::Failed)->create(['account_id' => $owner->account_id, 'created_at' => $troubled]);
    $this->actingAs($owner);

    $page = visit(route('socialhq.ai-usage.show'));

    waitFor($page, "@ai-usage-row-{$answered->id}");
    waitFor($page, "@ai-usage-row-{$failed->id}");
    $page->assertMissing("@ai-usage-day-rows-{$quiet->format('Y-m-d')}")
        ->assertSeeIn("@ai-usage-day-status-{$troubled->format('Y-m-d')}", '1 failed or blocked');

    $page->click("@ai-usage-day-toggle-{$quiet->format('Y-m-d')}");
    waitFor($page, "@ai-usage-day-rows-{$quiet->format('Y-m-d')} [data-testid^=\"ai-usage-row-\"]");

    $page->click("@ai-usage-toggle-{$answered->id}");
    waitFor($page, "@ai-usage-detail-{$answered->id}");
    $page->assertSeeIn("@ai-usage-call-id-{$answered->id}", $answered->litellm_call_id);

    $page->click('@ai-usage-collapse-all');
    waitForGone($page, "@ai-usage-day-rows-{$today->format('Y-m-d')}");
    $page->assertNoJavaScriptErrors();
});

test('the failed or blocked card filters the summaries and the opened days', function () use ($usageOwner) {
    $owner = $usageOwner();
    $day = CarbonImmutable::now('UTC')->subDay()->setTime(12, 0);
    $answered = AiUsageLog::factory()->create(['account_id' => $owner->account_id, 'created_at' => $day]);
    $failed = AiUsageLog::factory()->status(AiUsageStatus::Failed)->create(['account_id' => $owner->account_id, 'created_at' => $day->addHour()]);
    $this->actingAs($owner);

    $page = visit(route('socialhq.ai-usage.show'));
    waitFor($page, "@ai-usage-row-{$answered->id}");
    $page->assertSeeIn('@ai-usage-total-calls', '2');

    $page->click('@ai-usage-problems');
    waitForCondition($page, "new URLSearchParams(location.search).get('status') === '".AiUsagePage::PROBLEMS."'");
    waitForText($page, '@ai-usage-total-calls', '1');
    waitFor($page, "@ai-usage-row-{$failed->id}");
    waitForGone($page, "@ai-usage-row-{$answered->id}");
    $page->assertSeeIn('@ai-usage-count', '1 of 2 requests')
        ->assertNoJavaScriptErrors();
});
