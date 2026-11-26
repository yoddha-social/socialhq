<?php

declare(strict_types=1);

// Brand knowledge documents, links, the brand look, SEO keywords, "Read our website" and
// "Check what the AI reads" (Settings › Brand knowledge).
// Loaded inside the workspace group in routes/socialhq.php (auth, account ready,
// workspace; prefix socialhq; names socialhq.*).

use App\SocialHq\Http\Controllers\BrandKeywordsController;
use App\SocialHq\Http\Controllers\BrandKnowledgeSearchController;
use App\SocialHq\Http\Controllers\BrandLookController;
use App\SocialHq\Http\Controllers\KnowledgeDocumentController;
use App\SocialHq\Http\Controllers\KnowledgeLinkController;
use App\SocialHq\Http\Controllers\WebsiteCrawlController;
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

// SEO keywords: the whole catalogue on every change, the check of a file's keywords before import,
// and what one post gets.
Route::put('brand/keywords', [BrandKeywordsController::class, 'update'])->name('brand.keywords.update');
Route::post('brand/keywords/check', [BrandKeywordsController::class, 'check'])
    ->middleware('throttle:socialhq-knowledge-search')
    ->name('brand.keywords.check');
// "Keywords for this post" in the composer.
Route::get('brand/keywords/for-post', [BrandKeywordsController::class, 'forPost'])
    ->middleware('throttle:socialhq-knowledge-search')
    ->name('brand.keywords.for-post');

// Brand look: logo, colours, fonts and visual style.
Route::put('brand/look', [BrandLookController::class, 'update'])->name('brand.look.update');
Route::post('brand/look/logo', [BrandLookController::class, 'storeLogo'])
    ->middleware('throttle:socialhq-knowledge-uploads')
    ->name('brand.look.logo.store');
Route::get('brand/look/logo', [BrandLookController::class, 'showLogo'])->name('brand.look.logo.show');
Route::delete('brand/look/logo', [BrandLookController::class, 'destroyLogo'])->name('brand.look.logo.destroy');

// Google Docs, Sheets and Slides links (link-shared files), and "Read again".
Route::post('brand/links', [KnowledgeLinkController::class, 'store'])
    ->middleware('throttle:socialhq-knowledge-uploads')
    ->name('brand.links.store');
Route::post('brand/documents/{document}/refresh', [KnowledgeLinkController::class, 'refresh'])
    ->whereNumber('document')
    ->middleware('throttle:socialhq-knowledge-uploads')
    ->name('brand.documents.refresh');

// "Read our website" and its suggestions.
Route::post('brand/website', [WebsiteCrawlController::class, 'store'])
    ->middleware('throttle:socialhq-website-reads')
    ->name('brand.website.store');
Route::post('brand/website/{crawl}/suggestions/{suggestion}/accept', [WebsiteCrawlController::class, 'accept'])
    ->whereNumber('crawl')
    ->where('suggestion', '[a-z0-9_-]{1,40}')
    ->name('brand.website.accept');
Route::post('brand/website/{crawl}/suggestions/{suggestion}/skip', [WebsiteCrawlController::class, 'skip'])
    ->whereNumber('crawl')
    ->where('suggestion', '[a-z0-9_-]{1,40}')
    ->name('brand.website.skip');
