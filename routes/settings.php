<?php

use App\Http\Controllers\Settings\GmailAuthorizationController;
use App\Http\Controllers\Settings\GmailConnectionCheckController;
use App\Http\Controllers\Settings\GmailFailedMessageRetryController;
use App\Http\Controllers\Settings\GmailImportController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Middleware\RequirePasswordForOwnerEmailChange;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(RequirePasswordForOwnerEmailChange::class)
        ->name('profile.update');
});

Route::middleware(['auth'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('user-password.update');

    Route::get('data-sources/gmail/authorize', [GmailAuthorizationController::class, 'create'])
        ->middleware(RequirePassword::class)
        ->name('gmail.authorization.create');

    Route::get('settings/connections/gmail/callback', [GmailAuthorizationController::class, 'store'])
        ->name('gmail.authorization.store');

    Route::get('settings/connections', fn () => to_route('data_sources.gmail'))
        ->name('connections.edit');

    Route::post('data-sources/gmail/check', GmailConnectionCheckController::class)
        ->name('gmail.connection.check');

    Route::post('data-sources/gmail/import', GmailImportController::class)
        ->name('gmail.import');

    Route::post(
        'data-sources/gmail/failed-messages/{gmailMessageDiscovery}/retry',
        GmailFailedMessageRetryController::class,
    )->name('gmail.failed_messages.retry');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

Route::inertia('confirm-passkey', 'auth/confirm-passkey')
    ->middleware('auth')
    ->name('passkey.confirmation');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
