<?php

declare(strict_types=1);

// Monthly content plans and their items (/socialhq/plan/{month}, /socialhq/plan/items/{item}).
// Registered last: its {month} route would otherwise catch the other areas' paths.
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\AudienceController;
use App\SocialHq\Http\Controllers\Plan\BrandSettingsController;
use App\SocialHq\Http\Controllers\Plan\ItemCommentController;
use App\SocialHq\Http\Controllers\Plan\ItemCopyAiController;
use App\SocialHq\Http\Controllers\Plan\ItemCopyReviewController;
use App\SocialHq\Http\Controllers\Plan\ItemPostController;
use App\SocialHq\Http\Controllers\Plan\ItemVersionController;
use App\SocialHq\Http\Controllers\Plan\PlanController;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\SocialHq\Http\Controllers\Plan\PlanItemController;
use App\SocialHq\Http\Controllers\Plan\PlanItemWriteController;
use Illuminate\Support\Facades\Route;

Route::put('brand-settings', [BrandSettingsController::class, 'update'])->name('brand-settings.update');
Route::put('audience', [AudienceController::class, 'update'])->name('audience.update');

Route::get('items/{item}', [PlanItemController::class, 'show'])->whereNumber('item')->name('items.show');

// The item page: copy versions, copy review and the review thread.
Route::post('items/{item}/versions', [ItemVersionController::class, 'store'])->whereNumber('item')->name('items.versions.store');
Route::post('items/{item}/copy/ai', ItemCopyAiController::class)->whereNumber('item')->middleware('throttle:socialhq-plan-ai')->name('items.copy.ai');
Route::post('items/{item}/copy/submit', [ItemCopyReviewController::class, 'submit'])->whereNumber('item')->name('items.copy.submit');
Route::post('items/{item}/copy/approve', [ItemCopyReviewController::class, 'approve'])->whereNumber('item')->name('items.copy.approve');
Route::post('items/{item}/copy/request-changes', [ItemCopyReviewController::class, 'requestChanges'])->whereNumber('item')->name('items.copy.request-changes');
Route::post('items/{item}/comments', [ItemCommentController::class, 'store'])->whereNumber('item')->name('items.comments.store');
Route::post('items/{item}/post', [ItemPostController::class, 'store'])->whereNumber('item')->middleware('throttle:media-imports')->name('items.post.store');

Route::put('items/{item}', [PlanItemWriteController::class, 'update'])->whereNumber('item')->name('items.update');
Route::delete('items/{item}', [PlanItemWriteController::class, 'destroy'])->whereNumber('item')->name('items.destroy');
Route::post('items/{item}/move', [PlanItemWriteController::class, 'move'])->whereNumber('item')->name('items.move');
Route::post('items/{item}/accept', [PlanItemWriteController::class, 'accept'])->whereNumber('item')->name('items.accept');
Route::post('items/{item}/drop', [PlanItemWriteController::class, 'drop'])->whereNumber('item')->name('items.drop');
Route::post('items/{item}/restore', [PlanItemWriteController::class, 'restore'])->whereNumber('item')->name('items.restore');

Route::prefix('{month}')->where(['month' => '\d{4}-\d{2}'])->group(function () {
    Route::post('items', [PlanItemWriteController::class, 'store'])->name('items.store');
    Route::post('suggestions/accept', [PlanItemWriteController::class, 'acceptAll'])->name('suggestions.accept');
    Route::put('goals', [PlanController::class, 'updateGoals'])->name('goals.update');
    Route::post('submit', [PlanController::class, 'submit'])->name('submit');
    Route::post('approve', [PlanController::class, 'approve'])->name('approve');
    Route::post('generate', [PlanGenerationController::class, 'store'])
        ->middleware('throttle:socialhq-plan-generate')
        ->name('generate');
});

Route::get('{month?}', [PlanController::class, 'show'])->where('month', '\d{4}-\d{2}')->name('show');
