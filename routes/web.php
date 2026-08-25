<?php

use App\Http\Controllers\BreakdownController;
use App\Http\Controllers\BreakdownTransactionClassificationController;
use App\Http\Controllers\CategoryArchivalController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DataSourceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MerchantRuleController;
use App\Http\Controllers\ReceiptBreakdownController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\ReviewQueueLineItemCategoryController;
use App\Http\Controllers\ReviewQueueTransactionCategoryController;
use App\Http\Controllers\StatementImportController;
use App\Http\Controllers\StatementImportPreviewController;
use App\Http\Controllers\StatementMovementClassificationController;
use App\Http\Controllers\TransactionCategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionRefundLinkController;
use App\Http\Controllers\TransactionVoidController;
use App\Http\Controllers\TrendsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)
    ->middleware('auth')
    ->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('breakdown', BreakdownController::class)->name('breakdown.index');
    Route::get('trends', TrendsController::class)->name('trends.index');
    Route::get('data-sources/gmail', DataSourceController::class)->name('data_sources.gmail');
    Route::put(
        'breakdown/transactions/{transaction}/classification',
        [BreakdownTransactionClassificationController::class, 'update'],
    )->name('breakdown.transactions.classification.update');
    Route::resource('transactions', TransactionController::class)
        ->only(['index', 'store', 'update']);
    Route::resource('statement-imports', StatementImportController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->names([
            'index' => 'statement_imports.index',
            'create' => 'statement_imports.create',
            'store' => 'statement_imports.store',
            'show' => 'statement_imports.show',
        ]);
    Route::post('statement-import-previews', [StatementImportPreviewController::class, 'store'])
        ->name('statement_import_previews.store');
    Route::put(
        'statement-imports/{statement_import}/movements/{movement}/classification',
        [StatementMovementClassificationController::class, 'update'],
    )->scopeBindings()->name('statement_imports.movements.classification.update');
    Route::resource('merchant-rules', MerchantRuleController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->names([
            'index' => 'merchant_rules.index',
            'store' => 'merchant_rules.store',
            'update' => 'merchant_rules.update',
            'destroy' => 'merchant_rules.destroy',
        ]);
    Route::put('transactions/{transaction}/receipt-breakdown', [ReceiptBreakdownController::class, 'update'])
        ->name('transactions.receipt_breakdowns.update');
    Route::delete('transactions/{transaction}/receipt-breakdown', [ReceiptBreakdownController::class, 'destroy'])
        ->name('transactions.receipt_breakdowns.destroy');
    Route::put('transactions/{transaction}/category', [TransactionCategoryController::class, 'update'])
        ->name('transactions.category.update');
    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update']);
    Route::post('categories/{category}/archival', [CategoryArchivalController::class, 'store'])
        ->name('categories.archival.store');
    Route::delete('categories/{category}/archival', [CategoryArchivalController::class, 'destroy'])
        ->name('categories.archival.destroy');
    Route::post('transactions/{refund}/refund-link', [TransactionRefundLinkController::class, 'store'])
        ->name('transactions.refund_link.store');
    Route::post('transactions/{transaction}/void', [TransactionVoidController::class, 'store'])
        ->name('transactions.void.store');
    Route::delete('transactions/{transaction}/void', [TransactionVoidController::class, 'destroy'])
        ->name('transactions.void.destroy');
    Route::get('review-queue', ReviewQueueController::class)
        ->name('review_queue.index');
    Route::put(
        'review-queue/transactions/{transaction}/category',
        [ReviewQueueTransactionCategoryController::class, 'update'],
    )->name('review_queue.transactions.category.update');
    Route::put(
        'review-queue/line-items/{line_item}/category',
        [ReviewQueueLineItemCategoryController::class, 'update'],
    )->name('review_queue.line_items.category.update');
    Route::resource('review-queue.fields', TransactionFieldReviewController::class)
        ->only(['update'])
        ->parameters(['review-queue' => 'transaction'])
        ->names(['update' => 'review_queue.fields.update']);
});

require __DIR__.'/settings.php';
