<?php

use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionFieldReviewController;
use App\Http\Controllers\TransactionVoidController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::resource('transactions', TransactionController::class)
        ->only(['index', 'store']);
    Route::post('transactions/{transaction}/void', [TransactionVoidController::class, 'store'])
        ->name('transactions.void.store');
    Route::delete('transactions/{transaction}/void', [TransactionVoidController::class, 'destroy'])
        ->name('transactions.void.destroy');
    Route::get('review-queue', ReviewQueueController::class)
        ->name('review_queue.index');
    Route::resource('review-queue.fields', TransactionFieldReviewController::class)
        ->only(['update'])
        ->parameters(['review-queue' => 'transaction'])
        ->names(['update' => 'review_queue.fields.update']);
});

require __DIR__.'/settings.php';
