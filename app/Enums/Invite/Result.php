<?php

declare(strict_types=1);

namespace App\Enums\Invite;

enum Result
{
    case WrongEmail;
    case Gone;
    case AlreadyAccepted;
    case AlreadyMember;
    case Accepted;
    case Declined;
    // Social HQ: the user's own account holds something (AcceptInvite::holdsOtherAccount).
    case OtherAccount;

    public function flashBanner(): string
    {
        return match ($this) {
            self::WrongEmail => __('settings.members.flash.wrong_email'),
            self::Gone => __('settings.members.flash.invite_workspace_gone'),
            self::AlreadyAccepted, self::AlreadyMember => __('settings.members.flash.already_member'),
            self::Accepted => __('settings.members.flash.invite_accepted'),
            self::Declined => __('settings.members.flash.invite_declined'),
            self::OtherAccount => __('settings.members.flash.other_account'),
        };
    }

    /**
     * @return 'danger'|'info'|'success'
     */
    public function flashStyle(): string
    {
        return match ($this) {
            self::WrongEmail, self::Gone, self::OtherAccount => 'danger',
            self::AlreadyAccepted, self::AlreadyMember, self::Declined => 'info',
            self::Accepted => 'success',
        };
    }
}
