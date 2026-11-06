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

use App\SocialHq\Http\Controllers\AccessPendingController;
use App\SocialHq\Http\Controllers\AccessRequestController;
use App\SocialHq\Http\Controllers\AiGraphicController;
use App\SocialHq\Http\Controllers\BrandKnowledgeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('socialhq')->name('socialhq.')->group(function () {
    Route::get('access', AccessPendingController::class)->name('access.pending');

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

    Route::get('brand', [BrandKnowledgeController::class, 'index'])->name('brand.index');
    Route::put('brand/profile', [BrandKnowledgeController::class, 'updateProfile'])->name('brand.profile.update');
    Route::post('brand/entries', [BrandKnowledgeController::class, 'store'])->name('brand.entries.store');
    Route::put('brand/entries/{entry}', [BrandKnowledgeController::class, 'update'])->whereNumber('entry')->name('brand.entries.update');
    Route::delete('brand/entries/{entry}', [BrandKnowledgeController::class, 'destroy'])->whereNumber('entry')->name('brand.entries.destroy');
});
