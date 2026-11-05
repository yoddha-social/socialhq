<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Post;
use App\Models\User;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiRoutesCommand;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Approval\RecordApprovalEvents;
use App\SocialHq\Http\Middleware\RedirectWhileAwaitingAccess;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Signup\AccessRequest;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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
    public function register(): void
    {
        AiRouter::registerGateway();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/socialhq'));

        if ($this->app->runningInConsole()) {
            $this->commands([AiRoutesCommand::class]);
        }

        Route::middleware('web')->group(base_path('routes/socialhq.php'));

        // People who signed up wait on one page until an admin lets them in.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RedirectWhileAwaitingAccess::class);

        // Admins hear about a sign-up once its email is confirmed (Google and
        // GitHub sign-ups arrive confirmed).
        Event::listen(Registered::class, fn (Registered $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);

        Post::created(RecordApprovalEvents::created(...));
        Post::updated(RecordApprovalEvents::updated(...));

        /*
         * Brand knowledge in TryPost's caption and idea prompts, which include this
         * view without passing a workspace. Reading the user's current workspace is
         * safe only there: their controllers authorize that same workspace. New
         * features must not rely on this: call BrandKnowledge::forPrompt() with the
         * workspace they work for and pass the text to their own prompt view.
         */
        View::composer('prompts.socialhq.brand_knowledge', function ($view): void {
            if ($view->offsetExists('brand_knowledge')) {
                return;
            }

            $workspace = auth()->user() instanceof User ? auth()->user()->currentWorkspace : null;

            $view->with('brand_knowledge', $workspace === null ? '' : BrandKnowledge::forPrompt($workspace));
        });

        RateLimiter::for('socialhq-ai-graphics', fn (Request $request): Limit => Limit::perHour(AiGraphics::userHourlyLimit())
            ->by((string) $request->user()?->id));

        // One share for the whole module: a second Inertia::share('socialhq') would replace this one.
        Inertia::share('socialhq', fn (): array => [
            'approvals' => ['membersAlwaysReviewed' => ApprovalRules::membersAlwaysReviewed()],
            'aiGraphics' => [
                'available' => (function (): bool {
                    $user = auth()->user();

                    return $user instanceof User && $user->currentWorkspace !== null && AiGraphics::canUse($user, $user->currentWorkspace);
                })(),
                'maxReferences' => (int) config('socialhq.ai_graphics.max_reference_images', 3),
                'maxReferenceKilobytes' => (int) config('socialhq.ai_graphics.max_reference_kilobytes', 10240),
            ],
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
