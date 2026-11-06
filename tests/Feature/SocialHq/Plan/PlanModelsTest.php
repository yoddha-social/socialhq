<?php

declare(strict_types=1);

use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;

test('changing an event date or name clears its confirmation', function () {
    $event = CalendarEvent::factory()->verified()->create();

    $event->update(['description' => 'Lamps and sweets.']);
    expect($event->fresh()->isVerified())->toBeTrue();

    $event->update(['starts_on' => $event->starts_on->addDay(), 'ends_on' => $event->ends_on->addDay()]);
    expect($event->fresh()->isVerified())->toBeFalse();
});

test('a brand sees the account dates and its own, not another brand\'s', function () {
    $item = PlanItem::factory()->create();
    $workspace = $item->workspace;

    $shared = CalendarEvent::factory()->create(['account_id' => $workspace->account_id]);
    $own = CalendarEvent::factory()->create(['account_id' => $workspace->account_id, 'workspace_id' => $workspace->id]);
    CalendarEvent::factory()->create();

    expect(CalendarEvent::query()->visibleTo($workspace)->pluck('id')->all())->toEqualCanonicalizing([$shared->id, $own->id]);
});

test('an item keeps its versions in order, its latest version and its brief', function () {
    $item = PlanItem::factory()->create();
    ItemVersion::factory()->for($item, 'item')->create(['caption' => 'First']);
    ItemVersion::factory()->for($item, 'item')->create(['caption' => 'Second']);
    $brief = Brief::factory()->for($item, 'item')->create();

    $item->refresh();

    expect($item->versions->pluck('number')->all())->toBe([1, 2])
        ->and($item->latestVersion->caption)->toBe('Second')
        ->and($item->brief->is($brief))->toBeTrue()
        ->and($brief->stage)->toBe(BriefStage::Todo);
});

test('reopening an approved plan sends it back for review', function () {
    $plan = ContentPlan::factory()->approved()->create();

    $plan->reopen();

    expect($plan->fresh()->status)->toBe(PlanStatus::InReview)
        ->and($plan->fresh()->approved_at)->toBeNull();
});
