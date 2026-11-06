<?php

declare(strict_types=1);

namespace App\SocialHq\Approval;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;
use Laravel\Passport\AccessToken;

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

        $action = match (true) {
            PostApproval::isRequest($from, $to) => 'requested',
            PostApproval::isApproval($from, $to) => 'approved',
            $from === PostStatus::PendingApproval && $to === PostStatus::Draft => self::caller()?->id === $post->getOriginal('approval_requested_by')
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
                $caller->token() instanceof AccessToken => 'agent',
                default => 'app',
            },
            'from_status' => $from?->value,
            'to_status' => $to->value,
        ]);
    }

    private static function caller(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
