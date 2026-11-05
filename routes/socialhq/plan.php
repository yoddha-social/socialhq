<?php

declare(strict_types=1);

// Monthly content plans and their items (/socialhq/plan/{month}, /socialhq/plan/items/{item}).
// Registered last: its {month} route would otherwise catch the other areas' paths.
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\ItemCommentController;
use App\SocialHq\Http\Controllers\Plan\ItemCopyAiController;
use App\SocialHq\Http\Controllers\Plan\ItemCopyReviewController;
use App\SocialHq\Http\Controllers\Plan\ItemVersionController;
use App\SocialHq\Http\Controllers\Plan\PlanController;
use App\SocialHq\Http\Controllers\Plan\PlanItemController;
use Illuminate\Support\Facades\Route;

Route::get('items/{item}', [PlanItemController::class, 'show'])->whereNumber('item')->name('items.show');

// The item page: copy versions, copy review and the review thread.
Route::post('items/{item}/versions', [ItemVersionController::class, 'store'])->whereNumber('item')->name('items.versions.store');
Route::post('items/{item}/copy/ai', ItemCopyAiController::class)->whereNumber('item')->middleware('throttle:socialhq-plan-ai')->name('items.copy.ai');
Route::post('items/{item}/copy/submit', [ItemCopyReviewController::class, 'submit'])->whereNumber('item')->name('items.copy.submit');
Route::post('items/{item}/copy/approve', [ItemCopyReviewController::class, 'approve'])->whereNumber('item')->name('items.copy.approve');
Route::post('items/{item}/copy/request-changes', [ItemCopyReviewController::class, 'requestChanges'])->whereNumber('item')->name('items.copy.request-changes');
Route::post('items/{item}/comments', [ItemCommentController::class, 'store'])->whereNumber('item')->name('items.comments.store');

Route::get('{month?}', [PlanController::class, 'show'])->where('month', '\d{4}-\d{2}')->name('show');
