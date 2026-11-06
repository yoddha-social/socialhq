<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiRoutesCommand;
use App\SocialHq\Ai\ApprovedProvidersGuard;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\CompanyAiAccess;
use App\SocialHq\Ai\Company\CompanyRouting;
use App\SocialHq\Ai\Company\Jobs\TearDownCompanyAi;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\Approval\ApprovalRules;
use App\SocialHq\Approval\RecordApprovalEvents;
use App\SocialHq\Companies\CompanyRequests;
use App\SocialHq\Companies\CompanySignup;
use App\SocialHq\Companies\PlatformAdminCommand;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Events\ImportEventsCommand;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\SocialHq\Http\Middleware\EnsureCompanyApproved;
use App\SocialHq\Http\Middleware\RedirectWhileAwaitingAccess;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\CalendarStorage;
use App\SocialHq\Knowledge\Calendars\PruneCalendarUploadsCommand;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Search\KnowledgeSearch;
use App\SocialHq\Knowledge\Search\LexicalKnowledgeSearch;
use App\SocialHq\Knowledge\TryPostPromptFocus;
use App\SocialHq\Production\BriefStorage;
use App\SocialHq\Secrets\SecretsKeyCommand;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\PasswordResetProof;
use App\SocialHq\Signup\SignupRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Telescope\Telescope;

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

        // Which company's LiteLLM key AI calls use: decided once per request or job.
        $this->app->scoped(CompanyRouting::class);

        // Words today; an embeddings search would be bound here instead (see LexicalKnowledgeSearch).
        $this->app->bind(KnowledgeSearch::class, LexicalKnowledgeSearch::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/socialhq'));

        // Platform admins run this server: they let companies in.
        Gate::define(PlatformAdmins::GATE, fn (User $user): bool => PlatformAdmins::is($user));

        // A deleted company's team, key and provider keys leave LiteLLM too.
        Account::deleting(function (Account $account): void {
            $teamId = CompanyAi::query()->where('account_id', $account->id)->value('litellm_team_id');

            if (filled($teamId)) {
                TearDownCompanyAi::dispatch((string) $teamId, ProviderCredential::query()->forAccount($account)->pluck('provider')->all())->afterCommit();
            }
        });

        // No SDK request reaches a provider outside SOCIALHQ_AI_APPROVED_PROVIDERS, whoever makes it.
        Event::listen(ApprovedProvidersGuard::EVENTS, [ApprovedProvidersGuard::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([AiRoutesCommand::class, ImportEventsCommand::class, PruneCalendarUploadsCommand::class, PlatformAdminCommand::class, SecretsKeyCommand::class]);
        }

        // Provider keys are write-only: never flashed back as old input after a
        // validation error, and hidden from Telescope's request records.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->dontFlash(['provider_key']);
            }
        });

        if (class_exists(Telescope::class)) {
            Telescope::hideRequestParameters(['provider_key', 'credential_values', 'litellm_params.api_key']);
            Telescope::hideResponseParameters(['key', 'token']);
        }

        // Settings › AI: adding or testing a key calls the provider. 20 a minute per person.
        RateLimiter::for('socialhq-company-ai', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->user()?->id));

        Route::middleware('web')->group(base_path('routes/socialhq.php'));

        // People who signed up wait on one page until an admin lets them in.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RedirectWhileAwaitingAccess::class);

        // Only approved companies work: owners of waiting companies wait, suspended companies pause.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', EnsureCompanyApproved::class);

        // Admins hear about a sign-up once its email is confirmed (Google and
        // GitHub sign-ups arrive confirmed).
        Event::listen(Registered::class, fn (Registered $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? AccessRequests::notifyAdmins($event->user) : null);

        // Platform admins hear about a company request the same way.
        Event::listen(Registered::class, fn (Registered $event) => $event->user instanceof User ? CompanyRequests::notifyPlatformAdmins($event->user) : null);
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User ? CompanyRequests::notifyPlatformAdmins($event->user) : null);
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

            $view->with('brand_knowledge', $workspace === null ? '' : BrandKnowledge::forPrompt($workspace, TryPostPromptFocus::of($view->getData())));
        });

        RateLimiter::for('socialhq-ai-graphics', fn (Request $request): Limit => Limit::perHour(AiGraphics::userHourlyLimit())
            ->by((string) $request->user()?->id));
        RateLimiter::for('socialhq-plan-generate', fn (Request $request): Limit => Limit::perHour(PlanGenerationController::HOURLY_LIMIT)->by((string) $request->user()?->id));

        // AI copy and briefs on plan items: 20 an hour per person.
        RateLimiter::for('socialhq-plan-ai', fn (Request $request): Limit => Limit::perHour(20)
            ->by((string) $request->user()?->id));

        // Brief files go with the brief, its item or its plan (rows cascade, files don't).
        BriefStorage::registerCleanup();
        DocumentStorage::registerCleanup();

        // Brand knowledge documents: 20 uploads an hour per person; "Check what the AI reads" 30 a minute.
        RateLimiter::for('socialhq-knowledge-uploads', fn (Request $request): Limit => Limit::perHour(20)->by((string) $request->user()?->id));
        RateLimiter::for('socialhq-knowledge-search', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));

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
            'companyAi' => [
                // Settings › AI is the account owner's, for an approved company only.
                'canManage' => CompanyAiAccess::canManage(auth()->user() instanceof User ? auth()->user() : null),
            ],
            'signup' => [
                // The login page's "Sign up": the company lists, or asking to start a company.
                'open' => SignupRules::isOpen() || CompanyRequests::enabled(),
                // Whether a work email joins its team's company (the company name field then says it may stay empty).
                'joinsByEmail' => SignupRules::isOpen(),
                // The register page's company name field (the server still decides on submit).
                'companyRequests' => CompanySignup::showsCompanyField(),
                // One query, 0 for anyone who can't decide (the workspace menu and the Members banner read it).
                'pendingRequests' => auth()->user() instanceof User ? AccessRequests::pendingCountFor(auth()->user()) : 0,
            ],
            // The platform admin link in the user menu, with the requests waiting for a decision.
            'platform' => (function (): array {
                $isAdmin = PlatformAdmins::is(auth()->user());

                return ['admin' => $isAdmin, 'pendingCompanies' => $isAdmin ? CompanyRequests::awaitingDecisionCount() : 0];
            })(),
        ]);
    }
}
