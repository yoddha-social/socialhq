<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiRoutesCommand;
use App\SocialHq\Ai\ApprovedProvidersGuard;
use App\SocialHq\Ai\Company\AiSyncCommand;
use App\SocialHq\Ai\Company\CompanyAi;
use App\SocialHq\Ai\Company\CompanyRouting;
use App\SocialHq\Ai\Company\Jobs\TearDownCompanyAi;
use App\SocialHq\Ai\Company\ProviderCredential;
use App\SocialHq\Ai\Usage\AiUsageLogging;
use App\SocialHq\AiGraphics\AiGraphicResults;
use App\SocialHq\AiGraphics\AiGraphics;
use App\SocialHq\AiGraphics\PruneAiGraphicResultsCommand;
use App\SocialHq\Approval\RecordApprovalEvents;
use App\SocialHq\Channels\ConnectInvite;
use App\SocialHq\Channels\PruneGoneChannelInsightsCommand;
use App\SocialHq\Companies\CompanyRequests;
use App\SocialHq\Companies\CreateCompanyCommand;
use App\SocialHq\Companies\PlatformAdminCommand;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Errors\ErrorPages;
use App\SocialHq\Events\ImportEventsCommand;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\SocialHq\Http\Middleware\ActAsConnectInviter;
use App\SocialHq\Http\Middleware\EnsureCompanyApproved;
use App\SocialHq\Http\Middleware\RedirectWhileAwaitingAccess;
use App\SocialHq\Http\Middleware\SecurityHeaders;
use App\SocialHq\Http\Middleware\ShareSocialHqProps;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\CalendarStorage;
use App\SocialHq\Knowledge\Calendars\PruneCalendarUploadsCommand;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Look\LogoStorage;
use App\SocialHq\Knowledge\Search\KnowledgeSearch;
use App\SocialHq\Knowledge\Search\LexicalKnowledgeSearch;
use App\SocialHq\Knowledge\TryPostPromptFocus;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticeCreated;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\BriefStorage;
use App\SocialHq\Queue\CompactHorizon;
use App\SocialHq\Secrets\SecretsKeyCommand;
use App\SocialHq\Secrets\SecretsReencryptCommand;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\PasswordResetProof;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
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

        // No Stripe webhook on a self-hosted install: it would take unsigned messages (socialhq.billing).
        if (config('trypost.self_hosted') && ! config('socialhq.billing.stripe_webhook')) {
            Cashier::ignoreRoutes();
        }

        // Three Horizon supervisors instead of TryPost's seven (socialhq.horizon).
        CompactHorizon::apply($this->app->make('config'));

        // A connect link's token is in its address: Telescope, when on, keeps no record of it.
        $config = $this->app->make('config');
        $config->set('telescope.ignore_paths', [...(array) $config->get('telescope.ignore_paths', []), 'socialhq/connect-invite*']);

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

        // The plan item a post was made from (ItemPosts), without changing TryPost's model.
        Post::resolveRelationUsing('planItems', fn (Post $post): BelongsToMany => $post->belongsToMany(PlanItem::class, ItemPosts::TABLE, 'post_id', 'plan_item_id'));

        // A deleted company's team, key and provider keys leave LiteLLM too.
        Account::deleting(function (Account $account): void {
            $teamId = CompanyAi::query()->where('account_id', $account->id)->value('litellm_team_id');

            if (filled($teamId)) {
                TearDownCompanyAi::dispatch((string) $teamId, ProviderCredential::query()->forAccount($account)->pluck('litellm_credential_name')->all())->afterCommit();
            }
        });

        // No SDK request reaches a provider outside SOCIALHQ_AI_APPROVED_PROVIDERS, whoever makes it.
        Event::listen(ApprovedProvidersGuard::EVENTS, [ApprovedProvidersGuard::class, 'handle']);
        // Settings › AI › Usage: one row per AI call (after the guard, so refused calls aren't logged as sent).
        AiUsageLogging::register($this->app);

        if ($this->app->runningInConsole()) {
            $this->commands([AiRoutesCommand::class, AiSyncCommand::class, CreateCompanyCommand::class, ImportEventsCommand::class, PruneAiGraphicResultsCommand::class, PruneCalendarUploadsCommand::class, PruneGoneChannelInsightsCommand::class, PlatformAdminCommand::class, SecretsKeyCommand::class, SecretsReencryptCommand::class]);
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

        // 403, 404, 500 and 503 render in the app's style for the browser.
        ErrorPages::register();

        // A guest holding a connect link walks TryPost's connect flow as the admin who sent it,
        // for that request only. It runs before `auth` (Laravel sorts `auth` ahead of anything
        // without a priority), and before the checks below, so they judge that admin.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ActAsConnectInviter::class);
        $this->app->make(Kernel::class)->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, ActAsConnectInviter::class);
        RateLimiter::for('socialhq-connect-invite', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
        RateLimiter::for('socialhq-connect-invite-links', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->user()?->id));

        // Browser safety headers on every response, errors and the maintenance page included.
        $this->app->make(Kernel::class)->prependMiddleware(SecurityHeaders::class);

        // People who signed up wait on one page until an admin lets them in.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', RedirectWhileAwaitingAccess::class);

        // The module's shared Inertia props, set on every request (ShareSocialHqProps says why not here).
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', ShareSocialHqProps::class);

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
            $focus = TryPostPromptFocus::of($view->getData());

            $view->with('brand_knowledge', $workspace === null ? '' : BrandKnowledge::forPrompt($workspace, $focus));
            // The SEO keywords, outside the knowledge budget. A rewrite of the person's caption
            // (shorten, rephrase, tone) only keeps the ones it has; new text gets the rules.
            $view->with('seo_keywords', $workspace === null ? [] : BrandKeywords::forPrompt($workspace, $focus));
            $view->with('seo_keywords_keep_only', data_get($view->getData(), 'writes_new_text') === false);
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
        // Old calendars: an upload and its confirmation each count, 30 imports an hour per person.
        RateLimiter::for('socialhq-calendar-imports', fn (Request $request): Limit => Limit::perHour(60)->by((string) $request->user()?->id));
        RateLimiter::for('socialhq-knowledge-search', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));
        // Document previews: reading a Word or PowerPoint file's text is real work, 60 a minute per person.
        RateLimiter::for('socialhq-knowledge-previews', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->id));
        // "Read our website": 5 runs an hour per person (each reads up to 25 pages and makes one AI call).
        RateLimiter::for('socialhq-website-reads', fn (Request $request): Limit => Limit::perHour(5)->by((string) $request->user()?->id));

        // Brand logos go with their workspace.
        LogoStorage::registerCleanup();

        // Old calendar uploads: the file goes with its import or its brand, and
        // uploads never imported go after a day.
        CalendarStorage::registerCleanup();
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command(PruneCalendarUploadsCommand::class)->hourly()->withoutOverlapping());
        AiGraphicResults::registerCleanup();

        // The notification bell: each person hears their own notices, and old ones go after a month.
        Broadcast::channel(NoticeCreated::channelFor('{userId}'), fn (User $user, string $userId): bool => $user->id === $userId);
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('model:prune', ['--model' => [Notice::class, ConnectInvite::class]])->daily());
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command(PruneAiGraphicResultsCommand::class)->daily()->withoutOverlapping());
    }
}
