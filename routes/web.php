<?php

use App\Http\Controllers\CategoryArchivalController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationIncidentAcknowledgementController;
use App\Http\Controllers\IntegrationIncidentReplayController;
use App\Http\Controllers\MerchantRuleController;
use App\Http\Controllers\ParserProfileController;
use App\Http\Controllers\ParserProfilePreviewController;
use App\Http\Controllers\ParserProfileSourceMessageController;
use App\Http\Controllers\ReceiptBreakdownController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SpendingNotificationRecoveryController;
use App\Http\Controllers\SpendingNotificationRetryController;
use App\Http\Controllers\TransactionCategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionRefundLinkController;
use App\Http\Controllers\TransactionVoidController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')
    ->middleware('auth')
    ->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post(
        'integration-incidents/{integrationIncident}/acknowledgement',
        IntegrationIncidentAcknowledgementController::class,
    )->name('integration_incidents.acknowledgement.store');
    Route::post(
        'integration-incidents/{integrationIncident}/replay',
        IntegrationIncidentReplayController::class,
    )->name('integration_incidents.replay.store');
    Route::get('reports/{currency}', ReportController::class)->name('reports.show');
    Route::resource('transactions', TransactionController::class)
        ->only(['index', 'store', 'update']);
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
    Route::get(
        'parser-profile-source-messages/{gmailMessageDiscovery}',
        [ParserProfileSourceMessageController::class, 'show'],
    )->name('parser_profiles.source_messages.show');
    Route::resource('parser-profiles', ParserProfileController::class)
        ->only(['index', 'store'])
        ->names([
            'index' => 'parser_profiles.index',
            'store' => 'parser_profiles.store',
        ]);
    Route::post('parser-profile-previews', [ParserProfilePreviewController::class, 'store'])
        ->name('parser_profile_previews.store');
    Route::post(
        'spending-notification-references/{spending_notification_reference}/recovery',
        [SpendingNotificationRecoveryController::class, 'store'],
    )->name('spending_notification_references.recovery.store');
    Route::post(
        'spending-notification-references/{spending_notification_reference}/retry',
        [SpendingNotificationRetryController::class, 'store'],
    )->name('spending_notification_references.retry.store');
    Route::resource('review-queue.fields', TransactionFieldReviewController::class)
        ->only(['update'])
        ->parameters(['review-queue' => 'transaction'])
        ->names(['update' => 'review_queue.fields.update']);
});

require __DIR__.'/settings.php';
