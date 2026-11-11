<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\SocialHq\Companies\CompanyAccess;
use App\Support\Social\LimitRetryPolicy;
use Illuminate\Console\Command;
use Throwable;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled';

    protected $description = 'Process scheduled posts and network limit retries that are due for publishing';

    public function handle(): void
    {
        // Social HQ: a paused company's posts wait until it is restored.
        $paused = CompanyAccess::pausedWorkspaceIds();

        Post::query()
            ->due()
            ->each(function (Post $post) use ($paused) {
                try {
                    if (isset($paused[$post->workspace_id])) {
                        return;
                    }

                    $claimed = Post::where('id', $post->id)
                        ->where('status', PostStatus::Scheduled)
                        ->update(['status' => PostStatus::Publishing]);

                    if ($claimed) {
                        PublishPost::dispatch($post);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->dispatchDueLimitRetries($paused);
    }

    /**
     * Posts a network refused for a limit wait in the database, not in a
     * delayed job, so a worker restart never loses them. Clearing retry_at
     * claims the post, so each retry is dispatched once.
     */
    /**
     * @param  array<string, true>  $paused
     */
    private function dispatchDueLimitRetries(array $paused): void
    {
        Post::query()
            ->dueForLimitRetry()
            ->each(function (Post $post) use ($paused): void {
                try {
                    if (isset($paused[$post->workspace_id])) {
                        return;
                    }

                    $claimed = Post::query()
                        ->whereKey($post->id)
                        ->dueForLimitRetry()
                        ->toBase()
                        ->update(['retry_at' => null, 'publication_updated_at' => now()]);

                    if ($claimed) {
                        PublishToSocialPlatform::dispatch(
                            $post,
                            LimitRetryPolicy::UNIQUE_ATTEMPT_OFFSET + LimitRetryPolicy::retriesSoFar($post->error_context),
                        );
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
