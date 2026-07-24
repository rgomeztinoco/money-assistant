<?php

namespace App\Actions\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvalidateOtherSessions
{
    /**
     * Invalidate every session except the one performing the credential change.
     */
    public function handle(User $user, ?string $currentSessionId): void
    {
        $sessions = DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $user->getKey());

        if ($currentSessionId !== null) {
            $sessions->where('id', '!=', $currentSessionId);
        }

        $sessions->delete();

        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->saveQuietly();
    }
}
