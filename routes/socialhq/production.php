<?php

declare(strict_types=1);

// Production: briefs and the board that tracks graphics and videos (/socialhq/plan/production).
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\ProductionBoardController;
use Illuminate\Support\Facades\Route;

Route::get('production', [ProductionBoardController::class, 'index'])->name('production');
