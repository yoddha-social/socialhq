<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

use App\Models\Account;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Props of Settings › AI › Usage (one company) and the platform admins'
 * AI usage page (every company). The company is always decided by the
 * controller, never by the request.
 */
final class AiUsagePage
{
    /** Periods the filter offers, in days. Nothing is kept longer than 90 (AiUsageLog::RETENTION_DAYS). */
    public const array PERIODS = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public const string DEFAULT_PERIOD = '30d';

    /**
     * @param  array{period?: string, status?: string, feature?: string, provider?: string, workspace?: string, company?: int|string}  $filters
     * @return array<string, mixed>
     */
    public static function props(?Account $account, array $filters): array
    {
        $period = array_key_exists((string) data_get($filters, 'period'), self::PERIODS) ? (string) data_get($filters, 'period') : self::DEFAULT_PERIOD;
        $since = now()->subDays(self::PERIODS[$period]);
        $platform = $account === null;
        $companyAccountId = $platform && filled(data_get($filters, 'company'))
            ? Company::query()->whereKey((int) data_get($filters, 'company'))->value('account_id')
            : null;

        // Company scope and period: what the filter lists and "anything at all?" read.
        $scoped = fn (): Builder => AiUsageLog::query()
            ->when($account, fn (Builder $query) => $query->forAccount($account))
            ->when($companyAccountId, fn (Builder $query) => $query->forAccount((string) $companyAccountId))
            ->where('created_at', '>=', $since);

        $filtered = fn (): Builder => $scoped()
            ->when(data_get($filters, 'status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(data_get($filters, 'feature'), fn (Builder $query, string $feature) => $query->where('feature', $feature))
            ->when(data_get($filters, 'provider'), fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when(! $platform ? data_get($filters, 'workspace') : null, fn (Builder $query, string $workspace) => $query->where('workspace_id', $workspace));

        return [
            'platform' => $platform,
            'filters' => [
                'period' => $period,
                'status' => data_get($filters, 'status'),
                'feature' => data_get($filters, 'feature'),
                'provider' => data_get($filters, 'provider'),
                'workspace' => $platform ? null : data_get($filters, 'workspace'),
                'company' => $companyAccountId === null ? null : (int) data_get($filters, 'company'),
            ],
            'hasData' => AiUsageLog::query()->when($account, fn (Builder $query) => $query->forAccount($account))->exists(),
            'totals' => [
                'calls' => $filtered()->count(),
                'paid' => $filtered()->paid()->count(),
                'problems' => $filtered()->problems()->count(),
                'cost' => round((float) $filtered()->sum('cost'), 6),
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
            ],
            'logs' => Inertia::scroll(fn (): LengthAwarePaginator => self::rows($filtered(), $platform))->matchOn('data.id'),
        ];
    }

    private static function rows(Builder $query, bool $platform): LengthAwarePaginator
    {
        $page = $query->with(['workspace:id,name', 'user:id,name'])
            ->latest('created_at')->latest('id')
            ->paginate((int) config('app.pagination.default'));

        $companies = $platform
            ? Company::query()->whereIn('account_id', $page->getCollection()->pluck('account_id')->filter()->unique())->pluck('name', 'account_id')
            : collect();

        return $page->through(fn (AiUsageLog $log): array => [
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
        ]);
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
