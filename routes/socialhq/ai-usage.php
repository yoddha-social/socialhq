<?php

declare(strict_types=1);

/*
| Settings › AI › Usage: the company's AI calls (account owner only). Loaded
| from routes/socialhq.php inside the authenticated workspace group, next to
| routes/socialhq/ai.php.
*/

use App\SocialHq\Http\Controllers\CompanyAi\AiUsageController;
use Illuminate\Support\Facades\Route;

Route::get('settings/ai/usage', AiUsageController::class)->name('ai-usage.show');
