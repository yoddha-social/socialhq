<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\OldCalendars;
use App\SocialHq\Knowledge\Calendars\PastPost;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\ContentPlan;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->import = CalendarImport::factory()->create(['workspace_id' => $this->workspace->id]);
    // Next month: the month being planned.
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
});

test('the planner reads the same month of earlier years first, then the best performers, then the newest, up to 60', function () {
    $sameMonth = collect(range(1, 3))->map(fn (int $years): PastPost => PastPost::factory()->create([
        'calendar_import_id' => $this->import->id,
        'posted_on' => $this->month->subYears($years)->addDays(9)->toDateString(),
        'title' => "Same month {$years}",
    ]));
    PastPost::factory()->create([
        'calendar_import_id' => $this->import->id,
        'posted_on' => $this->month->subMonths(4)->toDateString(),
        'engagement' => 900,
        'title' => 'Best',
    ]);
    PastPost::factory()->create([
        'calendar_import_id' => $this->import->id,
        'posted_on' => $this->month->subMonths(5)->toDateString(),
        'reach' => 5000,
        'title' => 'Reach only',
    ]);
    PastPost::factory()->count(70)->create([
        'calendar_import_id' => $this->import->id,
        'posted_on' => $this->month->subMonths(3)->toDateString(),
        'caption' => str_repeat('Long caption ', 40),
    ]);
    PastPost::factory()->create(['posted_on' => $this->month->subYear()->toDateString(), 'title' => 'Another brand']);

    $rows = OldCalendars::forPlanner($this->workspace->id, $this->month);

    expect($rows)->toHaveCount(OldCalendars::PLANNER_ROWS)
        ->and(array_column(array_slice($rows, 0, 3), 'title'))->toBe(['Same month 1', 'Same month 2', 'Same month 3'])
        ->and($rows[0]['date'])->toBe($sameMonth[0]->posted_on->toDateString())
        ->and($rows[3])->toMatchArray(['title' => 'Best', 'engagement' => 900, 'platform' => 'instagram', 'format' => 'Reel'])
        ->and($rows[4]['title'])->toBe('Reach only')
        ->and(array_column($rows, 'title'))->not->toContain('Another brand')
        ->and(mb_strlen($rows[10]['excerpt']))->toBeLessThanOrEqual(PlannerContext::EXCERPT_LENGTH)
        ->and(strlen(json_encode($rows)))->toBeLessThan(40_000);
});

test('the planner context carries the old calendar', function () {
    PastPost::factory()->create([
        'calendar_import_id' => $this->import->id,
        'posted_on' => $this->month->subYear()->toDateString(),
        'title' => 'Last year this month',
    ]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);

    $context = PlannerContext::build($plan->fresh(), CarbonImmutable::now('UTC')->startOfDay());

    expect($context['old_calendar'])->toHaveCount(1)
        ->and($context['old_calendar'][0]['title'])->toBe('Last year this month');
});
