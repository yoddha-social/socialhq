<?php

declare(strict_types=1);

// Events: the dated festivals, holidays and days brands plan around (/socialhq/plan/events).
// Loaded inside the group in routes/socialhq.php (auth, account ready, workspace;
// prefix socialhq/plan; names socialhq.plan.*).

use App\SocialHq\Http\Controllers\Plan\EventBrandCountriesController;
use App\SocialHq\Http\Controllers\Plan\EventController;
use App\SocialHq\Http\Controllers\Plan\EventCountryImportController;
use App\SocialHq\Http\Controllers\Plan\EventFlagController;
use App\SocialHq\Http\Controllers\Plan\EventHideController;
use App\SocialHq\Http\Controllers\Plan\EventImportController;
use App\SocialHq\Http\Controllers\Plan\EventVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('events', [EventController::class, 'index'])->name('events.index');
Route::post('events', [EventController::class, 'store'])->name('events.store');
Route::post('events/import', [EventImportController::class, 'store'])->name('events.import');
Route::post('events/countries', [EventCountryImportController::class, 'store'])->name('events.countries.store');
Route::post('events/confirm-shown', [EventVerificationController::class, 'storeShown'])->name('events.verify-shown');
Route::post('events/confirm-shown/undo', [EventVerificationController::class, 'destroyShown'])->name('events.verify-shown.undo');
Route::put('events/brand-countries', [EventBrandCountriesController::class, 'update'])->name('events.brand-countries.update');
Route::delete('events/flags/{flag}', [EventFlagController::class, 'destroy'])->whereNumber('flag')->name('events.flags.destroy');
Route::put('events/{event}', [EventController::class, 'update'])->whereNumber('event')->name('events.update');
Route::delete('events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('events.destroy');
Route::post('events/{event}/verify', [EventVerificationController::class, 'store'])->whereNumber('event')->name('events.verify');
Route::delete('events/{event}/verify', [EventVerificationController::class, 'destroy'])->whereNumber('event')->name('events.unverify');
Route::post('events/{event}/hide', [EventHideController::class, 'store'])->whereNumber('event')->name('events.hide');
Route::delete('events/{event}/hide', [EventHideController::class, 'destroy'])->whereNumber('event')->name('events.unhide');
Route::post('events/{event}/flags', [EventFlagController::class, 'store'])->whereNumber('event')->name('events.flags.store');
