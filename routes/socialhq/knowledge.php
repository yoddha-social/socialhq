<?php

declare(strict_types=1);

// Brand knowledge documents and "Check what the AI reads" (Settings › Brand knowledge).
// Loaded inside the workspace group in routes/socialhq.php (auth, account ready,
// workspace; prefix socialhq; names socialhq.*).

use App\SocialHq\Http\Controllers\BrandKnowledgeSearchController;
use App\SocialHq\Http\Controllers\KnowledgeDocumentController;
use Illuminate\Support\Facades\Route;

Route::post('brand/documents', [KnowledgeDocumentController::class, 'store'])
    ->middleware('throttle:socialhq-knowledge-uploads')
    ->name('brand.documents.store');
Route::get('brand/documents/{document}', [KnowledgeDocumentController::class, 'show'])->whereNumber('document')->name('brand.documents.show');
Route::put('brand/documents/{document}', [KnowledgeDocumentController::class, 'update'])->whereNumber('document')->name('brand.documents.update');
Route::delete('brand/documents/{document}', [KnowledgeDocumentController::class, 'destroy'])->whereNumber('document')->name('brand.documents.destroy');

Route::post('brand/search', BrandKnowledgeSearchController::class)
    ->middleware('throttle:socialhq-knowledge-search')
    ->name('brand.search');
