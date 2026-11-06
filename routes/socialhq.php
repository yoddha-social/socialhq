<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Social HQ routes
|--------------------------------------------------------------------------
|
| Loaded by App\SocialHq\SocialHqServiceProvider inside the "web" middleware
| group. Social HQ pages live under /socialhq/*, inside TryPost's
| authenticated area.
|
*/

use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\SocialHq\Http\Controllers\AccessPendingController;
use App\SocialHq\Http\Controllers\AccessRequestController;
use App\SocialHq\Http\Controllers\AiGraphicController;
use App\SocialHq\Http\Controllers\BrandKnowledgeController;
use App\SocialHq\Http\Controllers\CompanyPausedController;
use App\SocialHq\Http\Controllers\CompanyRequestController;
use Illuminate\Support\Facades\Route;

// After asking to start a company on the register form: nobody is logged in yet.
Route::get('socialhq/company/requested', [CompanyRequestController::class, 'requested'])->name('socialhq.company.requested');

Route::middleware('auth')->prefix('socialhq')->name('socialhq.')->group(function () {
    Route::get('access', AccessPendingController::class)->name('access.pending');

    // A company waiting for approval, a paused one, and someone with no workspace (EnsureCompanyApproved).
    Route::get('company', [CompanyRequestController::class, 'show'])->name('company.waiting');
    Route::put('company', [CompanyRequestController::class, 'update'])->name('company.update');
    Route::post('company/ask-again', [CompanyRequestController::class, 'askAgain'])->middleware('throttle:6,1')->name('company.ask-again');
    Route::get('company/paused', CompanyPausedController::class)->name('company.paused');
    Route::get('company/start', [CompanyRequestController::class, 'start'])->name('company.start');
    Route::post('company/start', [CompanyRequestController::class, 'store'])->middleware('throttle:6,1')->name('company.store');

    Route::prefix('platform')->name('platform.')->group(function () {
        require __DIR__.'/socialhq/platform.php';
    });

    Route::get('access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::post('access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve'])->name('access-requests.approve');
    Route::post('access-requests/{accessRequest}/decline', [AccessRequestController::class, 'decline'])->name('access-requests.decline');

    Route::post('ai-graphics', [AiGraphicController::class, 'store'])
        ->middleware('throttle:socialhq-ai-graphics')
        ->name('ai-graphics.store');
    Route::get('ai-graphics', [AiGraphicController::class, 'index'])->name('ai-graphics.index');
    Route::get('ai-graphics/{import}', [AiGraphicController::class, 'show'])->whereUuid('import')->name('ai-graphics.show');
    Route::get('ai-graphics/{import}/image', [AiGraphicController::class, 'image'])->whereUuid('import')->name('ai-graphics.image');
    Route::post('ai-graphics/{import}/attach', [AiGraphicController::class, 'attach'])
        ->whereUuid('import')
        ->middleware('throttle:media-imports')
        ->name('ai-graphics.attach');

    // Workspace pages: the same middleware as TryPost's (routes/app.php).
    Route::middleware([EnsureAccountReady::class, EnsureHasWorkspace::class])->group(function () {
        Route::get('brand', [BrandKnowledgeController::class, 'index'])->name('brand.index');
        Route::put('brand/profile', [BrandKnowledgeController::class, 'updateProfile'])->name('brand.profile.update');
        Route::post('brand/entries', [BrandKnowledgeController::class, 'store'])->name('brand.entries.store');
        Route::put('brand/entries/{entry}', [BrandKnowledgeController::class, 'update'])->whereNumber('entry')->name('brand.entries.update');
        Route::delete('brand/entries/{entry}', [BrandKnowledgeController::class, 'destroy'])->whereNumber('entry')->name('brand.entries.destroy');
        require __DIR__.'/socialhq/knowledge.php';
        require __DIR__.'/socialhq/calendars.php';
    });
});

// The planning layer (content plan, production board, events): one file per area,
// all under /socialhq/plan with route names socialhq.plan.*.
Route::middleware(['auth', EnsureAccountReady::class, EnsureHasWorkspace::class])
    ->prefix('socialhq/plan')
    ->name('socialhq.plan.')
    ->group(function () {
        require __DIR__.'/socialhq/events.php';
        require __DIR__.'/socialhq/production.php';
        require __DIR__.'/socialhq/plan.php';
    });
