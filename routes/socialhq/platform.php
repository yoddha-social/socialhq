<?php

declare(strict_types=1);

/*
| Platform admin pages (/socialhq/platform/*, route names socialhq.platform.*).
| Required from routes/socialhq.php inside the `auth` group; the controllers
| answer 404 to anyone who isn't a platform admin.
*/

use App\SocialHq\Http\Controllers\Platform\CompanyController;
use Illuminate\Support\Facades\Route;

Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
Route::post('companies/{company}/approve', [CompanyController::class, 'approve'])->whereNumber('company')->name('companies.approve');
Route::post('companies/{company}/decline', [CompanyController::class, 'decline'])->whereNumber('company')->name('companies.decline');
Route::post('companies/{company}/suspend', [CompanyController::class, 'suspend'])->whereNumber('company')->name('companies.suspend');
Route::post('companies/{company}/restore', [CompanyController::class, 'restore'])->whereNumber('company')->name('companies.restore');
Route::put('companies/{company}/platform-ai', [CompanyController::class, 'platformAi'])->whereNumber('company')->name('companies.platform-ai');
