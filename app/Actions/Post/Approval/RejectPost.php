<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;

class RejectPost
{
    /**
     * Sends a pending post back to drafts. Its requested time stays on the draft.
     * An optional reason is kept as the approver's note on the post and sent
     * to the requester with the decision.
     */
    public static function execute(Post $post, User $approver, ?string $reason = null): Post
    {
        $reason = filled($reason) ? trim($reason) : null;

        return PostApproval::whilePending($post, function () use ($post, $approver, $reason): Post {
            $requester = $post->approvalRequester();

            $post->update([
                'status' => PostStatus::Draft,
                'schedule_mode' => null,
                ...PostApproval::transition(PostStatus::PendingApproval, PostStatus::Draft, $approver),
            ]);

            if ($reason !== null) {
                $post->notes()->create(['user_id' => $approver->id, 'body' => $reason]);
            }

            NotifyApprovalDecision::execute($post, ApprovalDecision::Rejected, $approver, $requester, $reason);

            return $post;
        });
    }
}
