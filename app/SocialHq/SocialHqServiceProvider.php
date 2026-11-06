<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Post;
use App\Models\User;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiRoutesCommand;
use App\SocialHq\Ai\ApprovedProvidersGuard;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Approval\RecordApprovalEvents;
use App\SocialHq\Events\ImportEventsCommand;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\SocialHq\Http\Middleware\RedirectWhileAwaitingAccess;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\CalendarStorage;
use App\SocialHq\Knowledge\Calendars\PruneCalendarUploadsCommand;
use App\SocialHq\Production\BriefStorage;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\PasswordResetProof;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
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

        // No SDK request reaches a provider outside SOCIALHQ_AI_APPROVED_PROVIDERS, whoever makes it.
        Event::listen(ApprovedProvidersGuard::EVENTS, [ApprovedProvidersGuard::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([AiRoutesCommand::class, ImportEventsCommand::class, PruneCalendarUploadsCommand::class]);
        }

        Route::middleware('web')->group(base_path('routes/socialhq.php'));

        // People who signed up wait on one page until an admin lets them in.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RedirectWhileAwaitingAccess::class);

        // Admins hear about a sign-up once its email is confirmed (Google and
        // GitHub sign-ups arrive confirmed).
        Event::listen(Registered::class, fn (Registered $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $event->user instanceof User ? PasswordResetProof::handle($event->user) : null);

        // Someone waiting for access who gets a workspace another way (an invite) is let in.
        User::updated(fn (User $user) => $user->wasChanged('current_workspace_id') ? AccessRequests::closeOnceJoined($user) : null);

        // Sign-up by email: 5 tries a minute and 20 an hour per address. Each limit needs its own key.
        RateLimiter::for('socialhq-register', fn (Request $request): array => [
            Limit::perMinute(5)->by("minute:{$request->ip()}"),
            Limit::perHour(20)->by("hour:{$request->ip()}"),
        ]);

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
        RateLimiter::for('socialhq-plan-generate', fn (Request $request): Limit => Limit::perHour(PlanGenerationController::HOURLY_LIMIT)->by((string) $request->user()?->id));

        // AI copy and briefs on plan items: 20 an hour per person.
        RateLimiter::for('socialhq-plan-ai', fn (Request $request): Limit => Limit::perHour(20)
            ->by((string) $request->user()?->id));

        // Brief files go with the brief, its item or its plan (rows cascade, files don't).
        BriefStorage::registerCleanup();

        // Old calendar uploads: the file goes with its import or its brand, and
        // uploads never imported go after a day.
        CalendarStorage::registerCleanup();
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command(PruneCalendarUploadsCommand::class)->hourly()->withoutOverlapping());

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
                // One query, 0 for anyone who can't decide (the workspace menu and the Members banner read it).
                'pendingRequests' => auth()->user() instanceof User ? AccessRequests::pendingCountFor(auth()->user()) : 0,
            ],
        ]);
    }
}
