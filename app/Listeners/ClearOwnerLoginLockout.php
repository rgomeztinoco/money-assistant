<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class ClearOwnerLoginLockout
{
    public function handle(Login $event): void
    {
        $ownerId = User::query()->oldest('id')->value('id');

        if ($ownerId === null || (string) $event->user->getAuthIdentifier() !== (string) $ownerId) {
            return;
        }

        Cache::forget(RecordOwnerLoginLockout::CACHE_KEY);
        RateLimiter::clear(RecordOwnerLoginFailure::FAILURES_KEY);
    }
}
