<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\SocialHq\Approval\ApprovalRules;

/**
 * Who changes what roles grant: the account owner, signed in to the app
 * (never an agent). Giving roles to people is MemberRoles.
 */
final class RoleAccess
{
    public static function mayManage(?User $user): bool
    {
        return $user !== null
            && $user->account_id !== null
            && $user->isAccountOwner()
            && ! ApprovalRules::usesAccessToken($user);
    }

    /**
     * A role of the owner's company; any other is not found.
     */
    public static function find(User $owner, string $roleId): Role
    {
        return Role::query()->forAccount((string) $owner->account_id)->findOrFail($roleId);
    }
}
