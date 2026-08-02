<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class RecordOwnerLoginLockout
{
    public const string CACHE_KEY = 'monitoring:owner-login-lockout';

    /**
     * Handle the event.
     */
    public function handle(Lockout $event): void
    {
        $ownerEmail = User::query()->oldest('id')->value('email');
        $attemptedEmail = $event->request->input(Fortify::username());

        if (! is_string($ownerEmail)
            || ! is_string($attemptedEmail)
            || Str::lower(trim($attemptedEmail)) !== Str::lower($ownerEmail)) {
            return;
        }

        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }
}
