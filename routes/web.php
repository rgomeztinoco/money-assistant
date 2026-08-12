<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRetirementController;
use App\Http\Controllers\CategoryTargetController;
use App\Http\Controllers\CategoryTargetRetirementController;
use App\Http\Controllers\CategoryTrashRestorationController;
use App\Http\Controllers\DailyExchangeRateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\IntegrationIncidentAcknowledgementController;
use App\Http\Controllers\IntegrationIncidentReplayController;
use App\Http\Controllers\MerchantRuleController;
use App\Http\Controllers\ParserProfileController;
use App\Http\Controllers\ParserProfilePreviewController;
use App\Http\Controllers\ParserProfileSourceMessageController;
use App\Http\Controllers\ReceiptBreakdownController;
use App\Http\Controllers\ReportingCurrencyController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SpendingNotificationRecoveryController;
use App\Http\Controllers\SpendingNotificationRetryController;
use App\Http\Controllers\TransactionCategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionRefundLinkController;
use App\Http\Controllers\TransactionVoidController;
use App\Http\Middleware\RequirePasskeyConfirmation;
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
    Route::get('insights', InsightsController::class)->name('insights.index');
    Route::resource('category-targets', CategoryTargetController::class)
        ->only(['store', 'update'])
        ->names([
            'store' => 'category_targets.store',
            'update' => 'category_targets.update',
        ]);
    Route::post('category-targets/{category_target}/retirement', CategoryTargetRetirementController::class)
        ->name('category_targets.retirement.store');
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
    Route::resource('daily-exchange-rates', DailyExchangeRateController::class)
        ->only(['index', 'store', 'update'])
        ->names([
            'index' => 'daily_exchange_rates.index',
            'store' => 'daily_exchange_rates.store',
            'update' => 'daily_exchange_rates.update',
        ]);
    Route::post('daily-exchange-rate-seed-requests/{dailyExchangeRateSeedRequest}/retry', [DailyExchangeRateController::class, 'retrySeed'])
        ->name('daily_exchange_rates.retry_seed');
    Route::put('reporting-currency', [ReportingCurrencyController::class, 'update'])
        ->name('reporting_currency.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware(RequirePasskeyConfirmation::class)
        ->name('categories.destroy');
    Route::post('trash/categories/{deletionId}/restoration', CategoryTrashRestorationController::class)
        ->name('trash.categories.restoration.store');
    Route::post('categories/{category}/retirement', [CategoryRetirementController::class, 'store'])
        ->name('categories.retirement.store');
    Route::delete('categories/{category}/retirement', [CategoryRetirementController::class, 'destroy'])
        ->name('categories.retirement.destroy');
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
