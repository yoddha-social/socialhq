<?php

declare(strict_types=1);

namespace App\SocialHq\Composer;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;

/**
 * What the composer's save tells the person (QA 2026-11-18, item 12): a toast
 * when the posts were saved as drafts or sent for approval, the two outcomes
 * that leave the person's view without a sign that it worked. Adding to the
 * queue has its own toast on the client; scheduling and publishing show the
 * post where it went.
 */
final class ComposerSaved
{
    /**
     * @param  iterable<Post>  $posts
     */
    public static function flash(iterable $posts, bool $queued = false): void
    {
        $saved = collect($posts)->filter();
        $count = $saved->count();

        if ($count === 0 || $queued) {
            return;
        }

        $key = match ($saved->first()->status) {
            PostStatus::Draft => 'socialhq.composer.saved.draft',
            PostStatus::PendingApproval => 'socialhq.composer.saved.pending_approval',
            default => null,
        };

        if ($key !== null) {
            session()->flash('flash.success', trans_choice($key, $count, ['count' => $count]));
        }
    }
}
