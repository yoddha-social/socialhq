<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

/**
 * What a notice in the bell is about. Each kind is switched on or off with
 * the email of the same row on Settings › Notifications (`Notices::FIELDS`).
 */
enum NoticeKind: string
{
    case PostPublished = 'post_published';
    case PostFailed = 'post_failed';
    case ChannelDisconnected = 'channel_disconnected';
    case PostsAtRisk = 'posts_at_risk';
    case PostNote = 'post_note';
    case ApprovalRequested = 'approval_requested';
    case PostApproved = 'post_approved';
    case PostRejected = 'post_rejected';
    case ChannelConnected = 'channel_connected';

    /** The colour family of the notice's icon. */
    public function tone(): string
    {
        return match ($this) {
            self::PostPublished, self::PostApproved, self::ChannelConnected => 'success',
            self::PostFailed, self::ChannelDisconnected => 'danger',
            self::PostsAtRisk, self::ApprovalRequested, self::PostRejected => 'warning',
            self::PostNote => 'info',
        };
    }
}
