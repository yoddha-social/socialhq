<?php

declare(strict_types=1);

namespace App\SocialHq\Approval;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;

/**
 * Writes the approval history from post saves, whichever entry point made
 * them (web, API, MCP, repurpose), so no upstream action needs an edit.
 * TryPost itself keeps only the latest approver and forgets rejections.
 */
final class RecordApprovalEvents
{
    public static function created(Post $post): void
    {
        if ($post->status === PostStatus::PendingApproval) {
            self::record($post, 'requested', null, $post->status);
        }
    }

    public static function updated(Post $post): void
    {
        if (! $post->wasChanged('status')) {
            return;
        }

        $from = PostStatus::tryFrom((string) ($post->getRawOriginal('status') ?? ''));
        $to = $post->status;

        if ($from === null || ! $to instanceof PostStatus) {
            return;
        }

        $caller = self::caller();

        // No caller (a job or command) is the system, which never withdraws.
        $action = match (true) {
            PostApproval::isRequest($from, $to) => 'requested',
            PostApproval::isApproval($from, $to) => 'approved',
            $from === PostStatus::PendingApproval && $to === PostStatus::Draft => $caller !== null && $caller->id === self::requesterBefore($post)
                ? 'withdrawn'
                : 'rejected',
            default => null,
        };

        if ($action !== null) {
            self::record($post, $action, $from, $to);
        }
    }

    private static function record(Post $post, string $action, ?PostStatus $from, PostStatus $to): void
    {
        $caller = self::caller();

        ApprovalEvent::query()->create([
            'workspace_id' => $post->workspace_id,
            'post_id' => $post->id,
            'user_id' => $caller?->id,
            'action' => $action,
            'via' => match (true) {
                $caller === null => 'system',
                ApprovalRules::usesAccessToken($caller) => 'agent',
                default => 'app',
            },
            'from_status' => $from?->value,
            'to_status' => $to->value,
        ]);
    }

    /**
     * Who asked for approval before this save, as Post::approvalRequester()
     * resolves it: the recorded requester, or the author for requests stored
     * before that was recorded. The save may have cleared the column.
     */
    private static function requesterBefore(Post $post): ?string
    {
        return $post->getOriginal('approval_requested_by') ?? $post->getOriginal('user_id');
    }

    private static function caller(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
