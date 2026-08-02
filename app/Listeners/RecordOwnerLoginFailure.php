<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class RecordOwnerLoginFailure
{
    public const string FAILURES_KEY = 'monitoring:owner-login-failures';

    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function handle(Failed $event): void
    {
        $ownerId = User::query()->oldest('id')->value('id');

        if ($ownerId === null || (string) $event->user?->getAuthIdentifier() !== (string) $ownerId) {
            return;
        }

        RateLimiter::hit(self::FAILURES_KEY, self::DECAY_SECONDS);

        if (RateLimiter::attempts(self::FAILURES_KEY) >= self::MAX_ATTEMPTS) {
            Cache::forever(RecordOwnerLoginLockout::CACHE_KEY, now()->toIso8601String());
        }
    }
}
