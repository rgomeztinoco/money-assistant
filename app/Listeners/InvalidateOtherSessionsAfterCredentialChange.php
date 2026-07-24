<?php

namespace App\Listeners;

use App\Actions\Security\InvalidateOtherSessions;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class InvalidateOtherSessionsAfterCredentialChange
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private InvalidateOtherSessions $invalidateOtherSessions,
        private Request $request,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(
        PasswordUpdatedViaController
        |RecoveryCodeReplaced
        |RecoveryCodesGenerated
        |TwoFactorAuthenticationConfirmed
        |TwoFactorAuthenticationDisabled
        |TwoFactorAuthenticationEnabled
        |PasskeyDeleted
        |PasskeyRegistered $event,
    ): void {
        /** @var User $user */
        $user = $event->user;
        $this->invalidateOtherSessions->handle(
            $user,
            $this->request->hasSession() ? $this->request->session()->getId() : null,
        );
    }
}
