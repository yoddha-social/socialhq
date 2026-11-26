<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;

/*
 * The Production board, clicked through (audit 2026-11-24, CQ-02: the first
 * screen to get its check under the rule that every screen change ships one).
 * A card moves by dragging and by its "Move to" menu, and the move sticks.
 */

/**
 * An admin (an approver) with one brief to do on this month's plan.
 *
 * @return array{0: User, 1: Brief}
 */
function productionBoardSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, 'title' => 'Dashain offer reel']);
    $brief = Brief::factory()->for($item, 'item')->create(['stage' => BriefStage::Todo]);

    return [$user->fresh(), $brief];
}

test('dragging a card to another column moves the brief there, and it stays after a reload', function () {
    [$user, $brief] = productionBoardSetup();
    $this->actingAs($user);

    $page = visit(route('socialhq.plan.production'))->resize(1440, 900);
    waitFor($page, "@board-column-todo @board-card-{$brief->id}");

    $page->drag("@board-card-{$brief->id}", '@board-column-in_progress');
    waitForDatabase($page, fn (): bool => $brief->refresh()->stage === BriefStage::InProgress);
    waitFor($page, "@board-column-in_progress @board-card-{$brief->id}");

    expect($brief->refresh()->stage)->toBe(BriefStage::InProgress);

    $page = visit(route('socialhq.plan.production'))->resize(1440, 900);
    waitFor($page, "@board-column-in_progress @board-card-{$brief->id}");
    $page->assertNoJavaScriptErrors();
});

test('a card\'s "Move to" menu moves it without dragging', function () {
    // In review needs a round with files (ProductionBoard::assertMayMove), so the menu moves it to In progress.
    [$user, $brief] = productionBoardSetup();
    $this->actingAs($user);

    $page = visit(route('socialhq.plan.production'))->resize(1440, 900);
    waitFor($page, "@board-card-menu-{$brief->id}");

    $page->click("@board-card-menu-{$brief->id}");
    waitFor($page, "@board-card-move-{$brief->id}-in_progress");
    $page->click("@board-card-move-{$brief->id}-in_progress");

    waitForDatabase($page, fn (): bool => $brief->refresh()->stage === BriefStage::InProgress);
    waitFor($page, "@board-column-in_progress @board-card-{$brief->id}");

    expect($brief->refresh()->stage)->toBe(BriefStage::InProgress);
    $page->assertNoJavaScriptErrors();
});
