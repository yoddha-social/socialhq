<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Post;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Approval\RecordApprovalEvents;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Entry point of the Social HQ module: our features on top of TryPost.
 *
 * Everything Social HQ adds is wired from here (routes, migrations,
 * schedule, policies), so upstream TryPost files stay untouched apart
 * from the few edits listed in PATCHES.md.
 */
class SocialHqServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/socialhq'));

        Route::middleware('web')->group(base_path('routes/socialhq.php'));

        Post::created(RecordApprovalEvents::created(...));
        Post::updated(RecordApprovalEvents::updated(...));

        Inertia::share('socialhq', fn (): array => [
            'approvals' => ['membersAlwaysReviewed' => ApprovalRules::membersAlwaysReviewed()],
        ]);
    }
}
