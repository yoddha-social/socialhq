<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Closure;

/**
 * Whose AI a call uses: the company (account) it runs for. AiRouter asks
 * this for every route, so a company's calls go through its own LiteLLM
 * key and never another's.
 *
 * On the web the tenant is the signed-in user's current workspace (the
 * workspace the request works in). Jobs have no user: they wrap their AI
 * calls in run() with the workspace they carry, and a call made outside
 * run() in a job has no tenant and fails closed. run() always restores the
 * previous tenant, so nothing carries over to the next job a worker runs.
 */
final class AiTenant
{
    /** The platform's own AI (LITELLM_API_KEY): operator commands only. */
    public const PLATFORM = 'platform';

    /** Explicitly nobody: AI fails closed. */
    private const NONE = 'none';

    /**
     * @var list<Account|string>
     */
    private static array $stack = [];

    /**
     * Runs the callback with AI calls billed to this company.
     *
     * @template TResult
     *
     * @param  Account|Workspace|self::PLATFORM|null  $tenant
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(Account|Workspace|string|null $tenant, Closure $callback): mixed
    {
        self::$stack[] = match (true) {
            $tenant instanceof Workspace => ($tenant->relationLoaded('account') ? $tenant->account : $tenant->account()->first()) ?? self::NONE,
            $tenant instanceof Account => $tenant,
            $tenant === self::PLATFORM => self::PLATFORM,
            default => self::NONE,
        };

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * The account AI calls run for, self::PLATFORM, or null for nobody.
     */
    public static function current(): Account|string|null
    {
        if (self::$stack !== []) {
            $top = self::$stack[array_key_last(self::$stack)];

            return $top === self::NONE ? null : $top;
        }

        $user = auth()->user();

        return $user instanceof User ? $user->currentWorkspace?->account : null;
    }
}
