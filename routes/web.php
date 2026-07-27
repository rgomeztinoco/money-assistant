<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRetirementController;
use App\Http\Controllers\LearnedRuleController;
use App\Http\Controllers\LearnedRuleSuggestionAcceptanceController;
use App\Http\Controllers\LearnedRuleSuggestionController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SuspectedDuplicateResolutionController;
use App\Http\Controllers\TransactionCategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionRefundLinkController;
use App\Http\Controllers\TransactionVoidController;
use App\Http\Middleware\RequirePasskeyConfirmation;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::resource('transactions', TransactionController::class)
        ->only(['index', 'store']);
    Route::put('transactions/{transaction}/category', [TransactionCategoryController::class, 'update'])
        ->name('transactions.category.update');
    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update']);
    Route::resource('learned-rules', LearnedRuleController::class)
        ->only(['index', 'store'])
        ->names([
            'index' => 'learned_rules.index',
            'store' => 'learned_rules.store',
        ]);
    Route::delete('learned-rule-suggestions/{learned_rule_suggestion}', [LearnedRuleSuggestionController::class, 'destroy'])
        ->name('learned_rule_suggestions.destroy');
    Route::post('learned-rule-suggestions/{learned_rule_suggestion}/acceptance', [LearnedRuleSuggestionAcceptanceController::class, 'store'])
        ->name('learned_rule_suggestions.acceptance.store');
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
    Route::resource('review-queue.fields', TransactionFieldReviewController::class)
        ->only(['update'])
        ->parameters(['review-queue' => 'transaction'])
        ->names(['update' => 'review_queue.fields.update']);
});

require __DIR__.'/settings.php';
