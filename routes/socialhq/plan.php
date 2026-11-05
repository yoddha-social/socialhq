<?php

declare(strict_types=1);

// Monthly content plans and their items (/socialhq/plan/{month}, /socialhq/plan/items/{item}).
// Registered last: its {month} route would otherwise catch the other areas' paths.
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\PlanController;
use App\SocialHq\Http\Controllers\Plan\PlanItemController;
use Illuminate\Support\Facades\Route;

Route::get('items/{item}', [PlanItemController::class, 'show'])->whereNumber('item')->name('items.show');
Route::get('{month?}', [PlanController::class, 'show'])->where('month', '\d{4}-\d{2}')->name('show');
