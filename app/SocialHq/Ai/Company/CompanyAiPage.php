<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\Gateway\LiteLlmAdmin;
use App\SocialHq\Companies\Company;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;

/**
 * The props of Settings › AI. Nothing here is secret: providers are named
 * by their last four characters, and the virtual key never leaves the
 * model (it is hidden and never read here).
 */
final class CompanyAiPage
{
    /**
     * @return array<string, mixed>
     */
    public static function props(Account $account): array
    {
        $company = Company::forAccount($account);
        $ai = CompanyAi::forAccount($account);
        $credentials = ProviderCredential::query()->forAccount($account)->inFallbackOrder()->get();
        $usesPlatform = (bool) $company?->uses_platform_ai && filled(config('socialhq.ai.litellm.key'));

        return [
            'blocker' => app(CompanyAiManager::class)->setupBlocker(),
            'usesPlatformAi' => $usesPlatform,
            'catalogue' => array_values(array_map(fn (ProviderCatalogue $provider): array => $provider->toProps(), ProviderCatalogue::all())),
            'providers' => $credentials->map(fn (ProviderCredential $credential): array => [
                'id' => $credential->id,
                'provider' => $credential->provider,
                'label' => $credential->label,
                'tier' => $credential->tier->value,
                // null: every task the plan offers.
                'tasks' => $credential->tasks,
                'servedTasks' => array_map(fn (AiTask $task): string => $task->value, $credential->servedTasks()),
                'models' => (object) ($credential->models ?? []),
                'last4' => $credential->last4,
                'status' => $credential->status->value,
                'lastError' => $credential->last_error,
                'lastTestedAt' => $credential->last_tested_at?->toIso8601String(),
                'options' => ['accountId' => data_get($credential->options, 'account_id')],
                'retired' => $credential->catalogue()?->offers($credential->tier) !== true,
            ])->values(),
            'tasks' => self::taskSummary($credentials, $usesPlatform),
            'maxKeys' => (int) config('socialhq.company_ai.max_keys', 20),
            'budget' => [
                'monthlyUsd' => $ai?->monthly_budget_usd === null ? null : (float) $ai->monthly_budget_usd,
                'duration' => $ai->budget_duration ?? (string) config('socialhq.company_ai.budget_duration', '30d'),
            ],
            'spend' => Inertia::defer(fn (): ?array => filled($ai?->litellm_team_id) ? app(LiteLlmAdmin::class)->teamSpend((string) $ai->litellm_team_id) : null),
        ];
    }

    /**
     * Which key each task uses first: the company's first key that serves
     * it, "platform" for a company on the platform's AI, or null when the
     * task has none. `count` is how many of the company's keys serve it.
     *
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return list<array{task: string, provider: string|null, label: string|null, count: int}>
     */
    private static function taskSummary(Collection $credentials, bool $usesPlatform): array
    {
        return array_map(function (AiTask $task) use ($credentials, $usesPlatform): array {
            $serving = $credentials->filter(fn (ProviderCredential $credential): bool => $credential->serves($task));
            $first = $serving->first();
            $platformServes = $usesPlatform && in_array($task->value, (array) config('socialhq.ai.litellm.tasks', []), true);

            return [
                'task' => $task->value,
                'provider' => $first?->provider ?? ($credentials->isEmpty() && $platformServes ? 'platform' : null),
                'label' => $first?->label,
                'count' => $serving->count(),
            ];
        }, AiTask::cases());
    }
}
