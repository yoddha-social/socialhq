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
     * Only an approved company's owner creates workspaces. An account with
     * no company row (an invited person's own empty account) creates none.
     */
    public static function mayCreateWorkspaces(User $user): bool
    {
        if (! config('trypost.self_hosted') || ! config('socialhq.companies.required', true)) {
            return true;
        }

        return Company::query()
            ->where('account_id', $user->account_id)
            ->where('status', CompanyStatus::Approved)
            ->exists();
    }

    /**
     * Whether this user may ask to start a company from the account they
     * have: they own it, it has no workspace and isn't a company yet.
     */
    public static function mayAskForCompany(User $user): bool
    {
        return CompanyRequests::enabled()
            && $user->isAccountOwner()
            && ! $user->account()->first()?->workspaces()->exists()
            && self::companyOf($user) === null;
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
