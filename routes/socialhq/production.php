<?php

declare(strict_types=1);

// Production: briefs and the board that tracks graphics and videos (/socialhq/plan/production).
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\BriefAiController;
use App\SocialHq\Http\Controllers\Plan\BriefController;
use App\SocialHq\Http\Controllers\Plan\ProductionBoardController;
use Illuminate\Support\Facades\Route;

Route::get('production', [ProductionBoardController::class, 'index'])->name('production');

// The brief of a plan item (one per item).
Route::post('items/{item}/brief', [BriefController::class, 'store'])->whereNumber('item')->name('items.brief.store');
Route::put('items/{item}/brief', [BriefController::class, 'update'])->whereNumber('item')->name('items.brief.update');
Route::post('items/{item}/brief/ai', BriefAiController::class)->whereNumber('item')->middleware('throttle:socialhq-plan-ai')->name('items.brief.ai');
