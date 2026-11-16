<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\User;
use App\Models\Workspace;

/**
 * What a user's company lets them do (self-hosted only; the SaaS mode keeps
 * upstream's rules). The company is the one of the user's current account.
 */
final class CompanyAccess
{
    /**
     * The company of the user's account, looked up once per request.
     */
    public static function companyOf(User $user): ?Company
    {
        if ($user->account_id === null) {
            return null;
        }

        $key = "socialhq.company:{$user->account_id}";
        $request = request();

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, Company::query()->where('account_id', $user->account_id)->first());
        }

        return $request->attributes->get($key);
    }

    /**
     * Whether only approved companies work (self-hosted, `socialhq.companies.required`).
     */
    public static function companiesRequired(): bool
    {
        return (bool) config('trypost.self_hosted') && (bool) config('socialhq.companies.required', true);
    }

    /**
     * Whether the user's account owns workspaces without being a company
     * while companies are required. Sign-up never makes one (CreateUser),
     * so this is a gap or an install that turned the requirement on later:
     * it is not approved, and its owner may ask for a company.
     */
    public static function hasWorkspacesWithoutCompany(User $user): bool
    {
        return self::companiesRequired()
            && $user->account_id !== null
            && self::companyOf($user) === null
            && Workspace::query()->where('account_id', $user->account_id)->exists();
    }

    /**
     * Why the workspace's API keys and MCP grants are refused (a lang key),
     * or null when its company works: a paused company, and, while companies
     * are required, an account that isn't an approved company. Self-hosted only.
     */
    public static function apiRefusal(Workspace $workspace): ?string
    {
        if (! config('trypost.self_hosted')) {
            return null;
        }

        $status = Company::query()->where('account_id', $workspace->account_id)->first(['status'])?->status;

        return match (true) {
            $status === CompanyStatus::Suspended => 'socialhq_companies.paused.api',
            $status === CompanyStatus::Approved, $status === null && ! self::companiesRequired() => null,
            default => 'socialhq_companies.not_approved.api',
        };
    }

    /**
     * Whether the workspace's company is suspended (its API keys and MCP
     * grants stop working, like its people). Self-hosted only.
     */
    public static function isPaused(Workspace $workspace): bool
    {
        return (bool) config('trypost.self_hosted') && Company::query()
            ->where('account_id', $workspace->account_id)
            ->where('status', CompanyStatus::Suspended)
            ->exists();
    }

    /**
     * The workspaces of paused companies, in one query (the scheduler checks
     * every due post against it). Self-hosted only.
     *
     * @return array<string, true> workspace id => true
     */
    public static function pausedWorkspaceIds(): array
    {
        if (! config('trypost.self_hosted')) {
            return [];
        }

        return Workspace::query()
            ->whereIn('account_id', Company::query()->where('status', CompanyStatus::Suspended)->select('account_id'))
            ->pluck('id')
            ->mapWithKeys(fn (string $id): array => [$id => true])
            ->all();
    }

    /**
     * Only an approved company's owner creates workspaces. An account with
     * no company row (an invited person's own empty account) creates none.
     */
    public static function mayCreateWorkspaces(User $user): bool
    {
        if (! self::companiesRequired()) {
            return true;
        }

        return Company::query()
            ->where('account_id', $user->account_id)
            ->where('status', CompanyStatus::Approved)
            ->exists();
    }

    /**
     * Whether this user may ask to start a company from the account they
     * have: they own it and it isn't a company yet, and either it has no
     * workspace or companies are required (an account with workspaces but
     * no company then asks to be approved).
     */
    public static function mayAskForCompany(User $user): bool
    {
        return CompanyRequests::enabled()
            && $user->isAccountOwner()
            && self::companyOf($user) === null
            && (self::companiesRequired() || ! $user->account()->first()?->workspaces()->exists());
    }

    /**
     * Who a paused company's owner writes to: the configured address, or
     * the platform admin who paused it.
     */
    public static function platformContact(Company $company): ?string
    {
        $configured = config('socialhq.companies.contact_email');

        if (filled($configured)) {
            return (string) $configured;
        }

        return $company->decider?->email;
    }
}
