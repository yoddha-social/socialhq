<?php

declare(strict_types=1);

// Events: the dated festivals, holidays and days brands plan around (/socialhq/plan/events).
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\EventController;
use Illuminate\Support\Facades\Route;

Route::get('events', [EventController::class, 'index'])->name('events.index');
