<?php

declare(strict_types=1);

namespace App\SocialHq;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
    }
}
