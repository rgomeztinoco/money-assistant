<?php

use App\Http\Controllers\AiCategoryProposalConfirmationController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRetirementController;
use App\Http\Controllers\CategoryTargetController;
use App\Http\Controllers\CategoryTargetRetirementController;
use App\Http\Controllers\DailyExchangeRateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\IntegrationIncidentAcknowledgementController;
use App\Http\Controllers\IntegrationIncidentReplayController;
use App\Http\Controllers\LearnedRuleBulkActionConfirmationController;
use App\Http\Controllers\LearnedRuleBulkActionController;
use App\Http\Controllers\LearnedRuleController;
use App\Http\Controllers\LearnedRuleHistoricalApplicationController;
use App\Http\Controllers\LearnedRulePreviewController;
use App\Http\Controllers\LearnedRuleRetirementController;
use App\Http\Controllers\LearnedRuleSuggestionController;
use App\Http\Controllers\LearnedRuleSuggestionPreviewController;
use App\Http\Controllers\ParserProfileController;
use App\Http\Controllers\ParserProfilePreviewController;
use App\Http\Controllers\ParserProfileSourceMessageController;
use App\Http\Controllers\ReceiptBreakdownConfirmationController;
use App\Http\Controllers\ReceiptBreakdownController;
use App\Http\Controllers\ReceiptProposalAttachmentController;
use App\Http\Controllers\ReportingCurrencyController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SpendingNotificationRecoveryController;
use App\Http\Controllers\SpendingNotificationRetryController;
use App\Http\Controllers\SuspectedDuplicateResolutionController;
use App\Http\Controllers\TransactionCategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionLearnedRulePreviewController;
use App\Http\Controllers\TransactionRefundLinkController;
use App\Http\Controllers\TransactionVoidController;
use App\Http\Middleware\RequirePasskeyConfirmation;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
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
        ->only(['index', 'store']);
    Route::post('transactions/{transaction}/receipt-proposal-attachments', [ReceiptProposalAttachmentController::class, 'store'])
        ->name('transactions.receipt_proposal_attachments.store');
    Route::put('receipt-breakdowns/{receipt_breakdown}', [ReceiptBreakdownController::class, 'update'])
        ->name('receipt_breakdowns.update');
    Route::delete('receipt-breakdowns/{receipt_breakdown}', [ReceiptBreakdownController::class, 'destroy'])
        ->name('receipt_breakdowns.destroy');
    Route::post('receipt-breakdowns/{receipt_breakdown}/confirmation', [ReceiptBreakdownConfirmationController::class, 'store'])
        ->name('receipt_breakdowns.confirmation.store');
    Route::delete('receipt-breakdowns/{receipt_breakdown}/confirmation', [ReceiptBreakdownConfirmationController::class, 'destroy'])
        ->name('receipt_breakdowns.confirmation.destroy');
    Route::put('transactions/{transaction}/category', [TransactionCategoryController::class, 'update'])
        ->name('transactions.category.update');
    Route::post('transactions/{transaction}/category-proposals/{ai_category_proposal}/confirmation', [AiCategoryProposalConfirmationController::class, 'store'])
        ->name('transactions.ai_category_proposals.confirmation.store');
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
    Route::resource('learned-rules', LearnedRuleController::class)
        ->only(['index', 'store', 'update'])
        ->names([
            'index' => 'learned_rules.index',
            'store' => 'learned_rules.store',
            'update' => 'learned_rules.update',
        ]);
    Route::post('learned-rule-previews', [LearnedRulePreviewController::class, 'store'])
        ->name('learned_rule_previews.store');
    Route::post('learned-rules/{learned_rule}/retirement', [LearnedRuleRetirementController::class, 'store'])
        ->name('learned_rules.retirement.store');
    Route::delete('learned-rules/{learned_rule}/retirement', [LearnedRuleRetirementController::class, 'destroy'])
        ->name('learned_rules.retirement.destroy');
    Route::post('learned-rules/{learned_rule}/historical-applications', [LearnedRuleHistoricalApplicationController::class, 'store'])
        ->name('learned_rules.historical_applications.store');
    Route::post('learned-rule-bulk-actions/{learned_rule_bulk_action}/confirmation', [LearnedRuleBulkActionConfirmationController::class, 'store'])
        ->name('learned_rule_bulk_actions.confirmation.store');
    Route::delete('learned-rule-bulk-actions/{learned_rule_bulk_action}', [LearnedRuleBulkActionController::class, 'destroy'])
        ->name('learned_rule_bulk_actions.destroy');
    Route::delete('learned-rule-suggestions/{learned_rule_suggestion}', [LearnedRuleSuggestionController::class, 'destroy'])
        ->name('learned_rule_suggestions.destroy');
    Route::post('learned-rule-suggestions/{learned_rule_suggestion}/preview', [LearnedRuleSuggestionPreviewController::class, 'store'])
        ->name('learned_rule_suggestions.preview.store');
    Route::post('transactions/{transaction}/learned-rule-preview', [TransactionLearnedRulePreviewController::class, 'store'])
        ->name('transactions.learned_rule_preview.store');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware(RequirePasskeyConfirmation::class)
        ->name('categories.destroy');
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
    Route::post('suspected-duplicates/{suspected_duplicate}/resolution', [SuspectedDuplicateResolutionController::class, 'store'])
        ->name('suspected_duplicates.resolution.store');
    Route::delete('suspected-duplicates/{suspected_duplicate}/resolution', [SuspectedDuplicateResolutionController::class, 'destroy'])
        ->name('suspected_duplicates.resolution.destroy');
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
