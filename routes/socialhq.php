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
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('socialhq')->name('socialhq.')->group(function () {
    Route::get('access', AccessPendingController::class)->name('access.pending');

    Route::get('access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::post('access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve'])->name('access-requests.approve');
    Route::post('access-requests/{accessRequest}/decline', [AccessRequestController::class, 'decline'])->name('access-requests.decline');
});
