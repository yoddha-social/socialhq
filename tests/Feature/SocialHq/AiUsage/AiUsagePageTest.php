<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Usage\AiUsageLog;
use App\SocialHq\Ai\Usage\AiUsagePage;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\PlatformAdmins;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->owner = User::factory()->create(['timezone' => 'UTC']);
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
            ->where('totals.answered', 2)
            ->where('totals.free', 1)
            ->where('totals.paid', 1)
            ->where('totals.problems', 1)
            ->where('totals.cost', 0.002)
            ->where('totals.tokens', 320)
            ->where('inScope', 3)
            ->where('breakdown.features.0.label', 'Caption assistant')
            ->where('breakdown.features.0.calls', 3)
            ->where('breakdown.providers.0.value', 'groq')
            ->where('breakdown.providers.0.calls', 2)
            ->has('days.data', 1)
            ->where('days.data.0.date', now('UTC')->format('Y-m-d'))
            ->where('days.data.0.calls', 3)
            ->where('days.data.0.problems', 1)
            ->where('options.workspaces.0.value', $this->workspace->id)
            ->where('options.people.0.value', $this->owner->id));
});

test('the log is grouped by day in the viewer\'s time zone', function () {
    $this->owner->update(['timezone' => 'Asia/Kathmandu']);
    // 20:00 UTC is 01:45 the next day in Kathmandu.
    $evening = CarbonImmutable::now('UTC')->subDays(3)->setTime(20, 0);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'created_at' => $evening]);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'created_at' => $evening->subHours(10)]);

    $this->actingAs($this->owner->fresh())
        ->get(route('socialhq.ai-usage.show'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today', CarbonImmutable::now('Asia/Kathmandu')->format('Y-m-d'))
            ->has('days.data', 2)
            ->where('days.data.0.date', $evening->setTimezone('Asia/Kathmandu')->format('Y-m-d'))
            ->where('days.data.1.date', $evening->subHours(10)->setTimezone('Asia/Kathmandu')->format('Y-m-d')));
});

test('the log pages by days', function () {
    foreach (range(0, AiUsagePage::DAYS_PER_PAGE) as $daysAgo) {
        AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'created_at' => now('UTC')->subDays($daysAgo)]);
    }

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('days.data', AiUsagePage::DAYS_PER_PAGE)
            ->where('series', fn ($series) => collect($series)->sum('calls') === AiUsagePage::DAYS_PER_PAGE + 1));
});

test('filters narrow the summaries, and a brand must be the company\'s own', function () {
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id]);
    AiUsageLog::factory()->status(AiUsageStatus::Failed)->create(['account_id' => $this->owner->account_id]);
    AiUsageLog::factory()->status(AiUsageStatus::RateLimited)->create(['account_id' => $this->owner->account_id]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['status' => 'failed', 'period' => '7d']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', 'failed')
            ->where('filters.period', '7d')
            ->where('totals.calls', 1)
            ->where('inScope', 3));

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['status' => AiUsagePage::PROBLEMS]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.status', AiUsagePage::PROBLEMS)
            ->where('totals.calls', 2)
            ->where('totals.problems', 2));

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['workspace' => $this->otherWorkspace->id]))
        ->assertSessionHasErrors('workspace');
});

test('the person filter keeps one person\'s calls', function () {
    $colleague = User::factory()->create();
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $colleague->id]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['user' => $colleague->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.user', $colleague->id)
            ->where('totals.calls', 1)
            ->has('options.people', 2));
});

test('the Spent card measures the last 30 days against the company\'s monthly budget', function () {
    CompanyAi::query()->create(['account_id' => $this->owner->account_id, 'litellm_team_id' => 'shq-co-usage', 'monthly_budget_usd' => 25]);
    AiUsageLog::factory()->paid(0.5)->create(['account_id' => $this->owner->account_id, 'created_at' => now()->subDays(20)]);
    AiUsageLog::factory()->paid(2)->create(['account_id' => $this->owner->account_id, 'created_at' => now()->subDays(40)]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.ai-usage.show', ['period' => '7d']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('budget.monthlyUsd', 25)
            ->where('budget.spentLastDays', 0.5)
            ->where('budget.days', AiUsagePage::BUDGET_DAYS));
});

test('a day\'s rows load under the page\'s filters, for the owner only', function () {
    $yesterday = now('UTC')->subDay()->setTime(12, 0);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id, 'workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'created_at' => $yesterday]);
    AiUsageLog::factory()->status(AiUsageStatus::Failed)->create(['account_id' => $this->owner->account_id, 'created_at' => $yesterday->addHour()]);
    AiUsageLog::factory()->create(['account_id' => $this->owner->account_id]);
    AiUsageLog::factory()->create(['account_id' => $this->otherCompany->account_id, 'created_at' => $yesterday]);

    $this->actingAs($this->owner)
        ->getJson(route('socialhq.ai-usage.day', ['day' => $yesterday->format('Y-m-d')]))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.status', 'failed')
        ->assertJsonPath('rows.1.workspace', $this->workspace->name)
        ->assertJsonPath('rows.1.user', $this->owner->name);

    $this->actingAs($this->owner)
        ->getJson(route('socialhq.ai-usage.day', ['day' => $yesterday->format('Y-m-d'), 'status' => AiUsagePage::PROBLEMS]))
        ->assertJsonPath('total', 1);

    $this->actingAs($this->owner)
        ->getJson(route('socialhq.ai-usage.day', ['day' => 'yesterday']))
        ->assertUnprocessable();

    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));

    $this->actingAs($member)
        ->getJson(route('socialhq.ai-usage.day', ['day' => $yesterday->format('Y-m-d')]))
        ->assertForbidden();
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
            ->where('totals.calls', 2)
            ->where('budget.monthlyUsd', null));

    $this->actingAs($admin)
        ->get(route('socialhq.platform.ai-usage.index', ['company' => $this->otherCompany->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totals.calls', 1));

    $this->actingAs($admin)
        ->getJson(route('socialhq.platform.ai-usage.day', ['day' => now($admin->timezone)->format('Y-m-d'), 'company' => $this->otherCompany->id]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('rows.0.company', 'Globex');
});

test('anyone else gets a 404 on the platform page', function () {
    $this->actingAs($this->owner)->get(route('socialhq.platform.ai-usage.index'))->assertNotFound();
    $this->actingAs($this->owner)
        ->getJson(route('socialhq.platform.ai-usage.day', ['day' => now()->format('Y-m-d')]))
        ->assertNotFound();
});
