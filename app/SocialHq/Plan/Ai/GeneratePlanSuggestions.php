<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\User\Locale;
use App\Enums\User\WeekStart;
use App\Models\User;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlannerOptions;
use App\SocialHq\Plan\PlanOptions;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Throwable;

/**
 * Drafts the run's days one week per AI call (PlannerOptions::weeks), so a
 * free model gets a small job: it is told how many posts each channel still
 * needs that week and on which days. Every answer goes through the hard rules
 * in code (SuggestionChecks), which repair what they can and refuse the rest
 * with a reason. When a week still has room afterwards, the model gets one
 * more try with the refused items and their reasons. A failed call is tried
 * once more too; a week that still fails keeps its earlier suggestions and is
 * listed as not drafted, and the other weeks go on. The team's must-haves are
 * placed by the checks too (the AI's item for each, else one the app adds),
 * and a week they fill gets no AI call at all.
 *
 * Each week is saved as soon as it is checked, replacing the earlier run's
 * untouched suggestions on its days (never planned or dropped items, or ones
 * with work); the plan's generation_weeks says how each week went. A job
 * whose run is no longer the plan's current one writes nothing more.
 */
class GeneratePlanSuggestions implements ShouldQueue
{
    use Queueable;

    /** How many days before an event its post may go out. */
    public const EVENT_LEAD_DAYS = 3;

    /**
     * No AI call starts once a run is this old (seconds): a call started
     * before it (AiTimeout::QUEUED at most) and its save still end inside
     * $timeout. The weeks not reached are listed as not drafted.
     */
    public const BUDGET_SECONDS = 540;

    public int $tries = 1;

    /**
     * The budget plus one queued AI call and a minute to save; under the
     * redis queue's retry_after (960 s), so no second worker takes the job.
     * ContentPlan::STALE_MINUTES stays above it.
     */
    public int $timeout = 900;

    /**
     * @param  string  $runId  the plan's generation_run_id when the run started
     */
    public function __construct(public int $planId, public string $runId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $plan = ContentPlan::query()->with('workspace')->find($this->planId);

        if ($plan === null || ! $this->isCurrentRun($plan)) {
            return;
        }

        $user = User::query()->find($plan->generation_requested_by);

        if ($user === null || $plan->workspace === null || ! PlanAccess::canWork($user, $plan->workspace)) {
            $this->markFailed('no_access');

            return;
        }

        $today = CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::now(Timezone::normalize($user->timezone))->toDateString(), 'UTC');
        $weekStart = $user->week_starts_on ?? WeekStart::DEFAULT;
        $run = PlannerOptions::forPlan($plan, PlanOptions::profile($plan->workspace));
        // Days already gone get no call (when the run reaches today).
        $weeks = ($today->betweenIncluded($run->from, $run->to) ? $run->between($today, $run->to) : $run)->weeks($weekStart);
        $deadline = now()->addSeconds(self::BUDGET_SECONDS);

        if (! $this->start($weeks)) {
            return;
        }

        $stopped = null;

        foreach ($weeks as $index => $week) {
            if (now()->greaterThan($deadline)) {
                $stopped = ['out_of_time', $index];

                break;
            }

            try {
                if (! $this->draftWeek($user, $run, $week, $index, $today, $weekStart, $deadline)) {
                    return;
                }
            } catch (AiUnavailableException $exception) {
                // No AI set up for the company is a configuration state, not an error to report.
                if (! $exception->isConfigurationState()) {
                    report($exception);
                }

                $stopped = ['ai_unavailable', $index];

                break;
            }
        }

        if ($stopped !== null) {
            [$reason, $from] = $stopped;

            // The weeks the AI won't draft still get the team's must-haves.
            foreach (array_slice($weeks, $from, preserve_keys: true) as $index => $week) {
                $checks = fn (bool $replacing, ?ContentPlan $locked = null): ?SuggestionChecks => $this->checks($locked, $run, $week, $today, $weekStart, $user->timezone, $replacing);

                if (! $this->notDrafted($index, $reason, null, $checks)) {
                    return;
                }
            }
        }

        $this->finish();
    }

    /**
     * A timeout or a crash outside handle(): the plan must not stay
     * "running". The weeks already saved stay; the others are not drafted.
     */
    public function failed(?Throwable $exception): void
    {
        $this->finish();
    }

    /**
     * One week: a call (and one more if it fails), the checks and the save,
     * then one retry with the refused items when the week still has room.
     * False when the plan moved on to another run.
     */
    private function draftWeek(User $user, PlannerOptions $run, PlannerOptions $week, int $index, CarbonImmutable $today, WeekStart $weekStart, CarbonInterface $deadline): bool
    {
        $checks = fn (bool $replacing, ?ContentPlan $locked = null): ?SuggestionChecks => $this->checks($locked, $run, $week, $today, $weekStart, $user->timezone, $replacing);
        $first = $checks(true);

        if ($first === null) {
            return false;
        }

        // The team's must-haves fill every channel's posts: the app adds them, and the AI isn't asked for more.
        if ($first->hasOpenMustHaves() && ! $first->hasOpenSlots()) {
            return $this->save($index, ['items' => [], 'summary' => '', 'context' => [], 'error' => null], $checks, replacing: true, calls: 0, filled: true) !== null;
        }

        $answer = $this->ask($user, $today, $first);
        $calls = 1;

        if ($answer !== null && $answer['error'] !== null && now()->lessThanOrEqualTo($deadline)) {
            $answer = $this->ask($user, $today, $checks(true));
            $calls = 2;
        }

        if ($answer === null) {
            return false;
        }

        if ($answer['error'] !== null) {
            return $this->notDrafted($index, $answer['error'], $calls, $checks);
        }

        $saved = $this->save($index, $answer, $checks, replacing: true, calls: $calls);

        if ($saved === null) {
            return false;
        }

        if ($calls > 1 || ! data_get($saved, 'open') || now()->greaterThan($deadline)) {
            return true;
        }

        // One more try for what the week still needs, told what was refused and why.
        $retry = $this->ask($user, $today, $checks(false), (array) data_get($saved, 'feedback'));

        if ($retry === null) {
            return false;
        }

        if ($retry['error'] !== null) {
            return $this->whileCurrent(fn (ContentPlan $plan) => self::withWeek($plan, $index, ['calls' => 2])->save());
        }

        return $this->save($index, $retry, $checks, replacing: false, calls: 2) !== null;
    }

    /**
     * A week the AI didn't draft (its call failed, the run ran out of time,
     * or the company has no AI): it keeps its earlier suggestions and is
     * listed as not drafted with why, and the team's must-haves no item
     * covers yet are added all the same. False when the plan moved on to
     * another run.
     *
     * @param  int|null  $calls  the week's calls, or null to keep the count it has
     * @param  Closure(bool, ContentPlan|null): ?SuggestionChecks  $checks
     */
    private function notDrafted(int $index, string $error, ?int $calls, Closure $checks): bool
    {
        $week = ['status' => 'failed', 'error' => $error, ...($calls === null ? [] : ['calls' => $calls])];
        $rules = $checks(false);

        if ($rules === null) {
            return false;
        }

        // A week already saved (the AI stopped answering on its second try) stays drafted.
        if (data_get($rules->plan()->generation_weeks, "{$index}.status") !== 'pending') {
            return true;
        }

        if (! $rules->hasOpenMustHaves()) {
            return $this->whileCurrent(fn (ContentPlan $plan) => self::withWeek($plan, $index, $week)->save());
        }

        return $this->save($index, ['items' => [], 'summary' => '', 'context' => [], 'error' => null], $checks, replacing: false, calls: $calls, week: $week) !== null;
    }

    /**
     * One AI call for the checks' week. Null when the plan moved on to
     * another run; an "error" when the call failed or its answer has no items.
     *
     * @param  list<array<string, string>>|null  $refused  the first answer's refused items, on the retry
     * @return array{items: list<mixed>, summary: string, context: array<string, mixed>, error: string|null}|null
     */
    private function ask(User $user, CarbonImmutable $today, ?SuggestionChecks $checks, ?array $refused = null): ?array
    {
        if ($checks === null) {
            return null;
        }

        $context = [];
        $failed = fn (string $error): array => ['items' => [], 'summary' => '', 'context' => $context, 'error' => $error];

        try {
            // The job has no signed-in user: the plan's workspace decides whose AI it uses.
            $response = AiTenant::run($checks->workspace(), function () use ($checks, $today, $user, $refused, &$context) {
                $context = PlannerContext::build($checks->plan(), $today, $user->timezone, $checks);
                $prompt = $refused === null ? $context : [...$context, 'retry' => ['rejected' => $refused]];

                return (new ContentPlanner($user->locale ?? Locale::DEFAULT, retry: $refused !== null))
                    ->prompt(json_encode($prompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            });
        } catch (AiUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $failed('ai_failed');
        }

        $items = data_get($response, 'items');

        if (! is_array($items)) {
            report(new RuntimeException(self::describeUnreadable($response)));

            return $failed(self::stoppedAtLimit($response) ? 'cut_off' : 'invalid_output');
        }

        return ['items' => array_values($items), 'summary' => trim((string) data_get($response, 'summary')), 'context' => $context, 'error' => null];
    }

    /**
     * Checks an answer under the plan's lock and saves what passes; the
     * first save of a week replaces the earlier run's untouched suggestions
     * on its days. Null when the plan moved on to another run.
     *
     * @param  array{items: list<mixed>, summary: string, context: array<string, mixed>, error: string|null}  $answer
     * @param  Closure(bool, ContentPlan|null): ?SuggestionChecks  $checks
     * @param  int|null  $calls  the week's calls, or null to keep the count it has
     * @param  bool  $filled  the week's must-haves fill every channel, so the AI wasn't asked
     * @param  array<string, mixed>  $week  what the week's record says over the counts (a week not drafted)
     * @return array{feedback: list<array<string, string>>, open: bool}|null
     */
    private function save(int $index, array $answer, Closure $checks, bool $replacing, ?int $calls, bool $filled = false, array $week = []): ?array
    {
        $saved = null;

        $current = $this->whileCurrent(function (ContentPlan $plan) use ($index, $answer, $checks, $replacing, $calls, $filled, $week, &$saved): void {
            // Read again under the lock: an item change may have come while the AI worked.
            $rules = $checks($replacing, $plan);
            $outcome = $rules->check(array_slice($answer['items'], 0, ContentPlanner::MAX_ITEMS));

            if ($replacing) {
                self::replaceableSuggestions($plan, $rules->week)->delete();
            }

            $positions = [];
            // Names and numbers the planner wrote that its sources don't have, for the team to check.
            $sources = UnsupportedDetails::against(UnsupportedDetails::sourceText($answer['context']));
            // The must-haves the app added itself hold only the team's words: nothing to check there.
            $items = [
                ...array_map(fn (array $attributes): array => [$attributes, true], $outcome['accepted']),
                ...array_map(fn (array $attributes): array => [$attributes, false], $outcome['added']),
            ];

            foreach ($items as [$attributes, $fromAi]) {
                $day = $attributes['scheduled_on'];
                $positions[$day] ??= (int) $plan->items()->whereDate('scheduled_on', $day)->max('position');
                $positions[$day]++;

                $plan->items()->create([
                    ...$attributes,
                    'position' => $positions[$day],
                    'unsupported_details' => $fromAi ? ($sources->in(trim("{$attributes['objective']}\n{$attributes['angle']}")) ?: null) : null,
                ]);
            }

            $before = (array) data_get($plan->generation_weeks, $index, []);

            $plan->forceFill([
                'generation_rejections' => [...(array) ($plan->generation_rejections ?? []), ...$outcome['rejections']],
                // The week's plan in the model's words; the retry's answer only adds items.
                'generation_summary' => $replacing && $answer['summary'] !== '' ? trim("{$plan->generation_summary}\n\n{$answer['summary']}") : $plan->generation_summary,
            ]);

            self::withWeek($plan, $index, [
                'status' => 'done',
                'error' => null,
                'proposed' => (int) data_get($before, 'proposed', 0) + count($answer['items']),
                'added' => (int) data_get($before, 'added', 0) + count($outcome['accepted']) + count($outcome['added']),
                'not_added' => (int) data_get($before, 'not_added', 0) + count($outcome['rejections']),
                'repaired' => (int) data_get($before, 'repaired', 0) + $outcome['repaired'],
                // Must-haves the AI missed (or wasn't asked for), which the app added itself.
                'must_haves_added' => (int) data_get($before, 'must_haves_added', 0) + count($outcome['added']),
                'filled' => $filled || (bool) data_get($before, 'filled', false),
                'calls' => $calls ?? (int) data_get($before, 'calls', 0),
                ...$week,
            ])->save();

            if ($outcome['accepted'] !== [] || $outcome['added'] !== []) {
                $plan->reopen();
            }

            $saved = ['feedback' => $outcome['feedback'], 'open' => $rules->hasOpenSlots()];
        });

        return $current ? $saved : null;
    }

    /**
     * The rules for a week, read from the plan as it is now (the locked one
     * when saving). Suggestions the run will still replace don't count as
     * planned: from the week's first day while it isn't saved yet, else from
     * the day after it.
     */
    private function checks(?ContentPlan $plan, PlannerOptions $run, PlannerOptions $week, CarbonImmutable $today, WeekStart $weekStart, ?string $userZone, bool $replacing): ?SuggestionChecks
    {
        $plan ??= ContentPlan::query()->with('workspace')->find($this->planId);

        if ($plan === null || ! $this->isCurrentRun($plan)) {
            return null;
        }

        $from = $replacing ? $week->from : $week->to->addDay();
        $replaceable = $from->greaterThan($run->to) ? [] : self::replaceableSuggestions($plan, $run->between($from, $run->to))->pluck('id')->all();

        return new SuggestionChecks($plan, $week, $today, $weekStart, $userZone, $replaceable);
    }

    /**
     * Lists the run's weeks as pending and clears the last run's summary and
     * refused items. False when the plan moved on to another run.
     *
     * @param  list<PlannerOptions>  $weeks
     */
    private function start(array $weeks): bool
    {
        return $this->whileCurrent(fn (ContentPlan $plan) => $plan->forceFill([
            'generation_weeks' => array_map(fn (PlannerOptions $week): array => [
                'from' => $week->from->toDateString(),
                'to' => $week->to->toDateString(),
                'status' => 'pending',
                'error' => null,
                'proposed' => 0,
                'added' => 0,
                'not_added' => 0,
                'repaired' => 0,
                'must_haves_added' => 0,
                'filled' => false,
                'calls' => 0,
            ], $weeks),
            'generation_rejections' => [],
            'generation_summary' => null,
        ])->save());
    }

    /**
     * Ends the run: idle when any week was drafted (the others are listed as
     * not drafted), failed with the first week's reason when none was.
     */
    private function finish(): void
    {
        $this->whileCurrent(function (ContentPlan $plan): void {
            $weeks = collect((array) ($plan->generation_weeks ?? []))
                ->map(fn (array $week): array => data_get($week, 'status') === 'pending' ? [...$week, 'status' => 'failed', 'error' => 'ai_failed'] : $week);
            $drafted = $weeks->contains(fn (array $week): bool => data_get($week, 'status') === 'done');

            $plan->forceFill([
                'generation_weeks' => $weeks->all() ?: null,
                'generation_status' => $drafted ? GenerationStatus::Idle : GenerationStatus::Failed,
                'generation_error' => $drafted ? null : ($weeks->pluck('error')->filter()->first() ?? 'ai_failed'),
                'generation_summary' => Str::limit(trim((string) $plan->generation_summary), 2000, '…') ?: null,
                ...($drafted ? ['generated_at' => now()] : []),
                'generation_started_at' => null,
                'generation_run_id' => null,
            ])->save();
        });
    }

    /**
     * Runs a change on the plan under its row lock while this run is still
     * its current one; false when it isn't.
     *
     * @param  Closure(ContentPlan): mixed  $change
     */
    private function whileCurrent(Closure $change): bool
    {
        return DB::transaction(function () use ($change): bool {
            $plan = ContentPlan::query()->with('workspace')->lockForUpdate()->find($this->planId);

            if ($plan === null || ! $this->isCurrentRun($plan)) {
                return false;
            }

            $change($plan);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function withWeek(ContentPlan $plan, int $index, array $changes): ContentPlan
    {
        $weeks = (array) ($plan->generation_weeks ?? []);
        $weeks[$index] = [...(array) data_get($weeks, $index, []), ...$changes];
        $plan->generation_weeks = $weeks;

        return $plan;
    }

    /**
     * Suggestions a new draft replaces: untouched ones (no copy, no brief) inside the run's days.
     *
     * @return HasMany<PlanItem, ContentPlan>
     */
    public static function replaceableSuggestions(ContentPlan $plan, PlannerOptions $options): HasMany
    {
        return $plan->items()
            ->where('status', ItemStatus::Suggested)
            ->whereDate('scheduled_on', '>=', $options->from->toDateString())
            ->whereDate('scheduled_on', '<=', $options->to->toDateString())
            ->whereDoesntHave('versions')
            ->whereDoesntHave('brief');
    }

    /**
     * Whether an answer without items stopped at its length limit: the model
     * ran out of room (or spent it reasoning) before it wrote the items.
     */
    public static function stoppedAtLimit(mixed $response): bool
    {
        return $response instanceof TextResponse && $response->steps->last()?->finishReason === FinishReason::Length;
    }

    /**
     * What the log needs to tell why an answer had no items: who answered, how
     * it stopped (a length stop means the output budget ran out) and its size.
     */
    private static function describeUnreadable(mixed $response): string
    {
        if (! $response instanceof TextResponse) {
            return 'Content plan draft without items: unexpected response '.get_debug_type($response);
        }

        $finish = $response->steps->last()?->finishReason->value ?? 'unknown';

        return sprintf(
            'Content plan draft without items: provider %s, model %s, finish reason %s, %d characters of text, %d output tokens.',
            $response->meta->provider ?? 'unknown',
            $response->meta->model ?? 'unknown',
            $finish,
            mb_strlen($response->text),
            $response->usage->completionTokens,
        );
    }

    private function isCurrentRun(ContentPlan $plan): bool
    {
        return $plan->generation_status === GenerationStatus::Running && $plan->generation_run_id === $this->runId;
    }

    /**
     * Ends this run as failed, unless the plan has moved on to another run since.
     */
    private function markFailed(string $reason): void
    {
        DB::transaction(function () use ($reason): void {
            $plan = ContentPlan::query()->lockForUpdate()->find($this->planId);

            if ($plan === null || ! $this->isCurrentRun($plan)) {
                return;
            }

            $plan->forceFill([
                'generation_status' => GenerationStatus::Failed,
                'generation_error' => $reason,
                'generation_started_at' => null,
                'generation_run_id' => null,
            ])->save();
        });
    }
}
