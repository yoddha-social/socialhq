<?php

declare(strict_types=1);

/*
| Old calendars on the Brand knowledge page (JSON for the import dialog).
| Required from routes/socialhq.php inside the workspace group: URLs under
| /socialhq/brand/calendars, names socialhq.brand.calendars.*.
*/

use App\SocialHq\Http\Controllers\CalendarImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('brand/calendars')->name('brand.calendars.')->group(function () {
    Route::post('/', [CalendarImportController::class, 'store'])->name('store');
    Route::put('{import}', [CalendarImportController::class, 'update'])->whereNumber('import')->name('update');
    Route::get('{import}/rows', [CalendarImportController::class, 'rows'])->whereNumber('import')->name('rows');
    Route::delete('{import}', [CalendarImportController::class, 'destroy'])->whereNumber('import')->name('destroy');
});
