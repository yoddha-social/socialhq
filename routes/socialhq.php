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

use App\SocialHq\Http\Controllers\AiGraphicController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('socialhq')->name('socialhq.')->group(function () {
    Route::post('ai-graphics', [AiGraphicController::class, 'store'])
        ->middleware('throttle:socialhq-ai-graphics')
        ->name('ai-graphics.store');
    Route::get('ai-graphics/{import}', [AiGraphicController::class, 'show'])->whereUuid('import')->name('ai-graphics.show');
});
