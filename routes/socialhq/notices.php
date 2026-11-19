<?php

declare(strict_types=1);

// The notification bell and its preferences.
// Loaded inside the workspace group in routes/socialhq.php (prefix socialhq; names socialhq.*).

use App\SocialHq\Http\Controllers\NoticeController;
use App\SocialHq\Http\Controllers\NoticePreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('notices', [NoticeController::class, 'index'])->name('notices.index');
Route::post('notices/read', [NoticeController::class, 'read'])->name('notices.read');
Route::get('notices/preferences', [NoticePreferenceController::class, 'show'])->name('notices.preferences.show');
Route::patch('notices/preferences', [NoticePreferenceController::class, 'update'])->name('notices.preferences.update');
