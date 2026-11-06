<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who runs this server (shq_platform_admins). They let companies in and
 * decide which may use the platform's AI keys. Not a TryPost role: a
 * workspace admin or account owner is not a platform admin.
 */
final class PlatformAdmins
{
    public const GATE = 'socialhq-platform-admin';

    public static function is(?User $user): bool
    {
        return $user !== null && DB::table('shq_platform_admins')->where('user_id', $user->id)->exists();
    }

    public static function grant(User $user): void
    {
        DB::table('shq_platform_admins')->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function revoke(User $user): void
    {
        DB::table('shq_platform_admins')->where('user_id', $user->id)->delete();
    }
}
