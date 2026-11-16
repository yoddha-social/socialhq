<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Usage\AiUsageLog;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\PlatformAdmins;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->company = Company::factory()->create(['account_id' => $this->owner->account_id, 'name' => 'Acme']);

    $other = User::factory()->create();
    $this->otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $this->otherCompany = Company::factory()->create(['account_id' => $other->account_id, 'name' => 'Globex']);
});

test('the owner sees only their company\'s calls, with totals', function () {
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);
    AiUsageLog::factory()->paid(0.002)->create(['account_id' => $this->owner->account_id, 'workspace_id' => $this->workspace->id]);
    AiUsageLog::factory()->status(AiUsageStatus::BlockedBudget)->create(['account_id' => $this->owner->account_id]);
    // Another company's call, and one older than the default period.
    AiUsageLog::factory()->create(['account_id' => $this->otherCompany->account_id]);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'created_at' => now()->subDays(45)]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('socialhq/settings/AiUsage')
            ->where('platform', false)
            ->where('hasData', true)
            ->where('totals.calls', 3)
            ->where('totals.paid', 1)
            ->where('totals.problems', 1)
            ->where('totals.cost', 0.002)
            ->has('logs.data', 3)
            ->where('logs.data.0.feature_label', 'Caption assistant')
            ->where('options.workspaces.0.value', $this->workspace->id));
});

test('filters narrow the list, and a brand must be the company\'s own', function () {
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id]);
    AiUsageLog::factory()->status(AiUsageStatus::Failed)->create(['account_id' => $this->owner->account_id]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['status' => 'failed', 'period' => '7d']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'failed')
            ->where('filters.period', '7d')
            ->has('logs.data', 1)
            ->where('logs.data.0.status', 'failed'));

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['workspace' => $this->otherWorkspace->id]))
        ->assertSessionHasErrors('workspace');
});

test('only the company owner sees the page', function () {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));

    $this->actingAs($member)->get(route('socialhq.ai-usage.show'))->assertForbidden();
});

test('platform admins see every company and can filter by one', function () {
    $admin = User::factory()->create();
    PlatformAdmins::grant($admin);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id]);
    AiUsageLog::factory()->create(['account_id' => $this->otherCompany->account_id]);

    $this->actingAs($admin)
        ->get(route('socialhq.platform.ai-usage.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('socialhq/platform/AiUsage')
            ->where('platform', true)
            ->has('logs.data', 2));

    $this->actingAs($admin)
        ->get(route('socialhq.platform.ai-usage.index', ['company' => $this->otherCompany->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.company', 'Globex'));
});

test('anyone else gets a 404 on the platform page', function () {
    $this->actingAs($this->owner)->get(route('socialhq.platform.ai-usage.index'))->assertNotFound();
});
