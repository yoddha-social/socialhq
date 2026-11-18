<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Companies\Company;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Props of Settings › AI › Usage (one company) and the platform admins'
 * AI usage page (every company). The company is always decided by the
 * controller, never by the request.
 *
 * The log is grouped by day in the viewer's time zone. The page gets each
 * day's totals (paged by days); a day's rows load when it is opened
 * (dayRows()). Days and chart buckets are counted in PHP, never with
 * engine-specific date functions, so PostgreSQL and MySQL agree.
 */
final class AiUsagePage
{
    /** Periods the filter offers, in days. Nothing is kept longer than 90 (AiUsageLog::RETENTION_DAYS). */
    public const array PERIODS = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public const string DEFAULT_PERIOD = '30d';

    /** The "Failed or blocked" filter: every status except answered. */
    public const string PROBLEMS = 'problems';

    /** Days per page of the log ("Load 7 more days", owner decision 2026-11-18). */
    public const int DAYS_PER_PAGE = 7;

    /** Most rows one opened day loads; the page says when a day has more. */
    public const int DAY_ROWS_LIMIT = 500;

    /** Days the Spent card measures against the monthly budget (LiteLLM's budget_duration). */
    public const int BUDGET_DAYS = 30;

    /**
     * @param  array{period?: string, status?: string, feature?: string, provider?: string, workspace?: string, company?: int|string, user?: string}  $filters
     * @return array<string, mixed>
     */
    public static function props(?Account $account, array $filters, User $viewer): array
    {
        $period = self::period($filters);
        $platform = $account === null;
        $companyAccountId = self::companyAccountId($account, $filters);
        $zone = Timezone::normalize($viewer->timezone);
        $since = now()->subDays(self::PERIODS[$period]);

        // Company scope and period: what the filters list and the "of N requests" count.
        $scoped = fn (): Builder => self::companyScope($account, $companyAccountId)->where('created_at', '>=', $since);

        $activity = null;
        $summarize = function () use (&$activity, $scoped, $filters, $platform, $zone, $period, $since): array {
            return $activity ??= self::activity(self::filtered($scoped(), $filters, $platform), $zone, $period, $since);
        };

        return [
            'platform' => $platform,
            'filters' => [
                'period' => $period,
                'status' => data_get($filters, 'status'),
                'feature' => data_get($filters, 'feature'),
                'provider' => data_get($filters, 'provider'),
                'workspace' => $platform ? null : data_get($filters, 'workspace'),
                'company' => $companyAccountId === null ? null : (int) data_get($filters, 'company'),
                'user' => data_get($filters, 'user'),
            ],
            'hasData' => AiUsageLog::query()->when($account, fn (Builder $query) => $query->forAccount($account))->exists(),
            'today' => CarbonImmutable::now($zone)->format('Y-m-d'),
            'totals' => fn (): array => data_get($summarize(), 'totals'),
            'inScope' => fn (): int => $scoped()->count(),
            'series' => fn (): array => data_get($summarize(), 'series'),
            'breakdown' => fn (): array => data_get($summarize(), 'breakdown'),
            'budget' => fn (): array => [
                'monthlyUsd' => self::monthlyBudget($account),
                'spentLastDays' => round((float) self::companyScope($account, $companyAccountId)
                    ->where('created_at', '>=', now()->subDays(self::BUDGET_DAYS))
                    ->sum('cost'), 6),
                'days' => self::BUDGET_DAYS,
            ],
            'options' => [
                'periods' => array_keys(self::PERIODS),
                'statuses' => array_map(fn (AiUsageStatus $status): string => $status->value, AiUsageStatus::cases()),
                'features' => $scoped()->distinct()->orderBy('feature')->pluck('feature')
                    ->map(fn (string $feature): array => ['value' => $feature, 'label' => self::featureLabel($feature)])->values(),
                'providers' => $scoped()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->values(),
                'workspaces' => $platform ? [] : Workspace::query()->where('account_id', $account->id)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Workspace $workspace): array => ['value' => $workspace->id, 'label' => $workspace->name])->values(),
                'companies' => $platform ? Company::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Company $company): array => ['value' => $company->id, 'label' => $company->name])->values() : [],
                'people' => User::query()
                    ->whereIn('id', $scoped()->whereNotNull('user_id')->distinct()->select('user_id'))
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])->values(),
            ],
            'days' => Inertia::scroll(fn (): LengthAwarePaginator => self::dayPage(data_get($summarize(), 'days')))->matchOn('data.date'),
        ];
    }

    /**
     * The rows of one day (in the viewer's zone) under the same filters, newest first.
     *
     * @param  array{day: string, period?: string, status?: string, feature?: string, provider?: string, workspace?: string, company?: int|string, user?: string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, limit: int}
     */
    public static function dayRows(?Account $account, array $filters, User $viewer): array
    {
        $period = self::period($filters);
        $platform = $account === null;
        $zone = Timezone::normalize($viewer->timezone);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', (string) data_get($filters, 'day'), $zone);

        $query = fn (): Builder => self::filtered(
            self::companyScope($account, self::companyAccountId($account, $filters))
                ->where('created_at', '>=', now()->subDays(self::PERIODS[$period]))
                ->where('created_at', '>=', $day->utc())
                ->where('created_at', '<', $day->addDay()->utc()),
            $filters,
            $platform,
        );

        $logs = $query()->with(['workspace:id,name', 'user:id,name'])
            ->latest('created_at')->latest('id')
            ->limit(self::DAY_ROWS_LIMIT)
            ->get();

        $companies = $platform
            ? Company::query()->whereIn('account_id', $logs->pluck('account_id')->filter()->unique())->pluck('name', 'account_id')
            : collect();

        return [
            'rows' => $logs->map(fn (AiUsageLog $log): array => self::row($log, $platform, $companies))->values()->all(),
            'total' => $query()->count(),
            'limit' => self::DAY_ROWS_LIMIT,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function period(array $filters): string
    {
        $period = (string) data_get($filters, 'period');

        return array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * The company a platform admin filtered by, as its account id.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function companyAccountId(?Account $account, array $filters): ?string
    {
        if ($account !== null || blank(data_get($filters, 'company'))) {
            return null;
        }

        $accountId = Company::query()->whereKey((int) data_get($filters, 'company'))->value('account_id');

        return $accountId === null ? null : (string) $accountId;
    }

    /**
     * @return Builder<AiUsageLog>
     */
    private static function companyScope(?Account $account, ?string $companyAccountId): Builder
    {
        return AiUsageLog::query()
            ->when($account, fn (Builder $query) => $query->forAccount($account))
            ->when($companyAccountId, fn (Builder $query) => $query->forAccount($companyAccountId));
    }

    /**
     * @param  Builder<AiUsageLog>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<AiUsageLog>
     */
    private static function filtered(Builder $query, array $filters, bool $platform): Builder
    {
        $status = data_get($filters, 'status');

        return $query
            ->when($status === self::PROBLEMS, fn (Builder $query) => $query->problems())
            ->when($status !== self::PROBLEMS ? $status : null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(data_get($filters, 'feature'), fn (Builder $query, string $feature) => $query->where('feature', $feature))
            ->when(data_get($filters, 'provider'), fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when(data_get($filters, 'user'), fn (Builder $query, string $user) => $query->where('user_id', $user))
            ->when(! $platform ? data_get($filters, 'workspace') : null, fn (Builder $query, string $workspace) => $query->where('workspace_id', $workspace));
    }

    /**
     * One pass over the filtered calls: totals, the chart (hours for the last
     * 24 hours, else days), counts by feature and provider, and each day's summary.
     *
     * @param  Builder<AiUsageLog>  $query
     * @return array{totals: array<string, int|float>, series: list<array{key: string, calls: int, problems: int}>, breakdown: array<string, mixed>, days: list<array<string, mixed>>}
     */
    private static function activity(Builder $query, string $zone, string $period, CarbonInterface $since): array
    {
        $hourly = $period === '1d';
        $totals = ['calls' => 0, 'answered' => 0, 'free' => 0, 'paid' => 0, 'problems' => 0, 'cost' => 0.0, 'tokens' => 0];
        $series = self::emptySeries($zone, $hourly, CarbonImmutable::instance($since));
        $features = [];
        $providers = [];
        $days = [];

        $calls = $query->toBase()
            ->select(['created_at', 'status', 'cost', 'feature', 'provider', 'prompt_tokens', 'completion_tokens'])
            ->orderByDesc('created_at')
            ->cursor();

        foreach ($calls as $call) {
            $at = CarbonImmutable::parse((string) data_get($call, 'created_at'), 'UTC')->setTimezone($zone);
            $cost = (float) data_get($call, 'cost', 0);
            $tokens = (int) data_get($call, 'prompt_tokens', 0) + (int) data_get($call, 'completion_tokens', 0);
            $answered = data_get($call, 'status') === AiUsageStatus::Ok->value;
            $feature = (string) data_get($call, 'feature');
            $provider = data_get($call, 'provider');
            $day = $at->format('Y-m-d');
            $bucket = $hourly ? $at->format('Y-m-d H') : $day;

            $counts = [
                'calls' => 1,
                'answered' => $answered ? 1 : 0,
                'free' => $answered && $cost <= 0 ? 1 : 0,
                'paid' => $cost > 0 ? 1 : 0,
                'problems' => $answered ? 0 : 1,
                'tokens' => $tokens,
            ];

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            $totals['cost'] += $cost;

            if (array_key_exists($bucket, $series)) {
                $series[$bucket]['calls']++;
                $series[$bucket]['problems'] += $counts['problems'];
            }

            $features[$feature] = ($features[$feature] ?? 0) + 1;

            if (filled($provider)) {
                $providers[$provider] = ($providers[$provider] ?? 0) + 1;
            }

            $days[$day] ??= ['date' => $day, 'calls' => 0, 'answered' => 0, 'free' => 0, 'paid' => 0, 'problems' => 0, 'tokens' => 0, 'cost' => 0.0, 'features' => []];

            foreach ($counts as $key => $value) {
                $days[$day][$key] += $value;
            }

            $days[$day]['cost'] += $cost;
            $days[$day]['features'][$feature] = ($days[$day]['features'][$feature] ?? 0) + 1;
        }

        $totals['cost'] = round($totals['cost'], 6);

        return [
            'totals' => $totals,
            'series' => array_values($series),
            'breakdown' => [
                'features' => self::ranked($features)->map(fn (int $calls, string $feature): array => ['value' => $feature, 'label' => self::featureLabel($feature), 'calls' => $calls])->values()->all(),
                'providers' => self::ranked($providers)->map(fn (int $calls, string $provider): array => ['value' => $provider, 'label' => $provider, 'calls' => $calls])->values()->all(),
            ],
            'days' => array_map(fn (array $day): array => [
                ...$day,
                'cost' => round($day['cost'], 6),
                'features' => self::ranked($day['features'])->map(fn (int $calls, string $feature): array => ['label' => self::featureLabel($feature), 'calls' => $calls])->values()->all(),
            ], array_values($days)),
        ];
    }

    /**
     * Every bucket of the period, oldest first, so quiet hours or days show as gaps.
     *
     * @return array<string, array{key: string, calls: int, problems: int}>
     */
    private static function emptySeries(string $zone, bool $hourly, CarbonImmutable $since): array
    {
        $unit = $hourly ? 'hour' : 'day';
        $cursor = $since->setTimezone($zone)->startOf($unit);
        $end = CarbonImmutable::now($zone);
        $series = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format($hourly ? 'Y-m-d H' : 'Y-m-d');
            $series[$key] = ['key' => $key, 'calls' => 0, 'problems' => 0];
            $cursor = $hourly ? $cursor->addHour() : $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  array<string, int>  $counts
     * @return Collection<string, int>
     */
    private static function ranked(array $counts): Collection
    {
        return collect($counts)->sortDesc();
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private static function dayPage(array $days): LengthAwarePaginator
    {
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            array_slice($days, ($page - 1) * self::DAYS_PER_PAGE, self::DAYS_PER_PAGE),
            count($days),
            self::DAYS_PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    private static function monthlyBudget(?Account $account): ?float
    {
        if ($account === null) {
            return null;
        }

        $budget = CompanyAi::forAccount($account)?->monthly_budget_usd;

        return $budget === null ? null : (float) $budget;
    }

    /**
     * @param  Collection<int|string, string>  $companies
     * @return array<string, mixed>
     */
    private static function row(AiUsageLog $log, bool $platform, Collection $companies): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at->toIso8601String(),
            'feature' => $log->feature,
            'feature_label' => self::featureLabel($log->feature),
            'task' => $log->task,
            'route' => $log->route,
            'status' => $log->status->value,
            'company' => $platform ? ($companies[$log->account_id] ?? null) : null,
            'workspace' => $log->workspace?->name,
            'user' => $log->user?->name,
            'provider' => $log->provider,
            'model' => $log->model,
            'model_group_requested' => self::group($log->model_group_requested),
            'model_group' => self::group($log->model_group),
            'fallbacks' => $log->fallbacks,
            'requests' => $log->requests,
            'cost' => $log->cost,
            'prompt_tokens' => $log->prompt_tokens,
            'completion_tokens' => $log->completion_tokens,
            'duration_ms' => $log->duration_ms,
            'error_code' => $log->error_code,
            'error_type' => $log->error_type,
            'call_id' => $log->litellm_call_id,
        ];
    }

    /**
     * A LiteLLM group without its company suffix: "shq-write@shq-co-…" reads "shq-write".
     */
    private static function group(?string $group): ?string
    {
        return $group === null ? null : Str::before($group, '@');
    }

    private static function featureLabel(string $feature): string
    {
        $key = "socialhq_ai_usage.features.{$feature}";

        return Lang::has($key) ? __($key) : Str::headline($feature);
    }
}
