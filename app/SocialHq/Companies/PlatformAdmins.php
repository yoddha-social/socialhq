<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\User;
use App\SocialHq\Support\PerRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who runs this server (shq_platform_admins). They let companies in and
 * decide which may use the platform's AI keys. Not a TryPost role: a
 * workspace admin or account owner is not a platform admin.
 */
final class PlatformAdmins
{
    public const GATE = 'socialhq-platform-admin';

    /**
     * Asked once per request (the menu, the middleware and the gate all ask).
     */
    public static function is(?User $user): bool
    {
        return $user !== null && PerRequest::remember(
            "platform-admin:{$user->id}",
            fn (): bool => DB::table('shq_platform_admins')->where('user_id', $user->id)->exists(),
        );
    }

    /**
     * @return Collection<int, User>
     */
    public static function users(): Collection
    {
        return User::query()->whereIn('id', DB::table('shq_platform_admins')->select('user_id'))->get();
    }

    public static function grant(User $user): void
    {
        DB::table('shq_platform_admins')->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        PerRequest::forget("platform-admin:{$user->id}");
    }

    public static function revoke(User $user): void
    {
        DB::table('shq_platform_admins')->where('user_id', $user->id)->delete();
        PerRequest::forget("platform-admin:{$user->id}");
    }
}
