<?php

declare(strict_types=1);

namespace App\SocialHq;

use App\Models\User;
use App\SocialHq\AiGraphics\AiGraphics;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('socialhq-ai-graphics', fn (Request $request): Limit => Limit::perHour(AiGraphics::userHourlyLimit())
            ->by((string) $request->user()?->id));

        Inertia::share('socialhq', fn (): array => [
            'aiGraphics' => [
                'available' => (function (): bool {
                    $user = auth()->user();

                    return $user instanceof User && $user->currentWorkspace !== null && AiGraphics::canUse($user, $user->currentWorkspace);
                })(),
                'maxReferences' => (int) config('socialhq.ai_graphics.max_reference_images', 3),
                'maxReferenceKilobytes' => (int) config('socialhq.ai_graphics.max_reference_kilobytes', 10240),
            ],
        ]);
    }
}
