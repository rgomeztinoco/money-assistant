<?php

use App\Http\Controllers\Settings\AgentAccessController;
use App\Http\Controllers\Settings\GmailAuthorizationController;
use App\Http\Controllers\Settings\GmailConnectionCheckController;
use App\Http\Controllers\Settings\GmailConnectionDisconnectController;
use App\Http\Controllers\Settings\GmailFailedMessageRetryController;
use App\Http\Controllers\Settings\GmailImportController;
use App\Http\Controllers\Settings\GmailReviewMessageController;
use App\Http\Controllers\Settings\GmailUnsupportedMessagesRetryController;
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

    Route::delete('data-sources/gmail/connection', GmailConnectionDisconnectController::class)
        ->middleware(RequirePassword::class)
        ->name('gmail.connection.destroy');

    Route::post('data-sources/gmail/import', GmailImportController::class)
        ->name('gmail.import');

    Route::post(
        'data-sources/gmail/failed-messages/{gmailMessageDiscovery}/retry',
        GmailFailedMessageRetryController::class,
    )->name('gmail.failed_messages.retry');

    Route::post(
        'data-sources/gmail/unsupported-messages/retry',
        GmailUnsupportedMessagesRetryController::class,
    )->name('gmail.unsupported_messages.retry');

    Route::post('data-sources/gmail/messages/{gmailMessageDiscovery}/retry', [GmailReviewMessageController::class, 'retry'])
        ->name('gmail.messages.retry');
    Route::post('data-sources/gmail/messages/{gmailMessageDiscovery}/dismiss', [GmailReviewMessageController::class, 'dismiss'])
        ->name('gmail.messages.dismiss');
    Route::delete('data-sources/gmail/messages/{gmailMessageDiscovery}/dismiss', [GmailReviewMessageController::class, 'restore'])
        ->name('gmail.messages.restore');

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

Route::middleware(['auth', RequirePassword::class])->group(function () {
    Route::get('settings/agent-access', [AgentAccessController::class, 'index'])->name('agent-access.index');
    Route::post('settings/agent-access/tokens/{token}/rotate', [AgentAccessController::class, 'rotate'])->name('agent-access.rotate');
    Route::delete('settings/agent-access/tokens/{token}', [AgentAccessController::class, 'destroy'])->name('agent-access.destroy');
    Route::post('settings/agent-access/tokens', [AgentAccessController::class, 'store'])->name('agent-access.store');
});
