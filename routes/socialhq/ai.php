<?php

declare(strict_types=1);

/*
| Settings › AI: a company's own AI providers (account owner only). Loaded
| from routes/socialhq.php inside the authenticated workspace group.
*/

use App\SocialHq\Http\Controllers\CompanyAi\CompanyAiBudgetController;
use App\SocialHq\Http\Controllers\CompanyAi\CompanyAiSettingsController;
use App\SocialHq\Http\Controllers\CompanyAi\ProviderCredentialController;
use App\SocialHq\Http\Controllers\CompanyAi\ProviderCredentialOrderController;
use App\SocialHq\Http\Controllers\CompanyAi\ProviderCredentialTestController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings/ai')->name('ai-settings.')->group(function () {
    Route::get('/', CompanyAiSettingsController::class)->name('show');

    // Each of these may call a provider through LiteLLM: 20 a minute per person.
    Route::middleware('throttle:socialhq-company-ai')->group(function () {
        Route::post('providers', [ProviderCredentialController::class, 'store'])->name('providers.store');
        Route::put('providers/{credential}', [ProviderCredentialController::class, 'update'])->whereNumber('credential')->name('providers.update');
        Route::delete('providers/{credential}', [ProviderCredentialController::class, 'destroy'])->whereNumber('credential')->name('providers.destroy');
        Route::post('providers/{credential}/test', ProviderCredentialTestController::class)->whereNumber('credential')->name('providers.test');
        Route::post('providers/{credential}/move', ProviderCredentialOrderController::class)->whereNumber('credential')->name('providers.move');
        Route::put('budget', CompanyAiBudgetController::class)->name('budget.update');
    });
});
