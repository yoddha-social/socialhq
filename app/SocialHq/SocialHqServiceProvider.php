<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Post;
use App\Models\User;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Approval\RecordApprovalEvents;
use App\SocialHq\Http\Middleware\RedirectWhileAwaitingAccess;
use App\SocialHq\Signup\AccessRequest;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
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

        // People who signed up wait on one page until an admin lets them in.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RedirectWhileAwaitingAccess::class);

        // Admins hear about a sign-up once its email is confirmed (Google and
        // GitHub sign-ups arrive confirmed).
        Event::listen(Registered::class, fn (Registered $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);

        Post::created(RecordApprovalEvents::created(...));
        Post::updated(RecordApprovalEvents::updated(...));

        Inertia::share('socialhq', fn (): array => [
            'approvals' => ['membersAlwaysReviewed' => ApprovalRules::membersAlwaysReviewed()],
            'signup' => [
                'open' => SignupRules::isOpen(),
                'pendingRequests' => (function (): int {
                    $user = auth()->user();

                    return $user instanceof User && AccessRequests::canDecide($user)
                        ? AccessRequest::query()->where('account_id', $user->account_id)->awaitingDecision()->count()
                        : 0;
                })(),
            ],
        ]);
    }
}
