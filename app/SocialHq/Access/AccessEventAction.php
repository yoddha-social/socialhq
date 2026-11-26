<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

enum AccessEventAction: string
{
    case RoleCreated = 'role_created';
    case RoleUpdated = 'role_updated';
    case RoleDeleted = 'role_deleted';
    case MemberRoleChanged = 'member_role_changed';
    case InviteSent = 'invite_sent';
    case AccessGranted = 'access_granted';
    case MemberRemoved = 'member_removed';
}
