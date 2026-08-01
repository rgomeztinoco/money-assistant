<?php

namespace App\Actions\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteOwnerAccount
{
    public function __construct(
        private EnsureOwnerAccountCanBeDeleted $ensureOwnerAccountCanBeDeleted,
    ) {}

    public function handle(User $owner): void
    {
        DB::transaction(function () use ($owner): void {
            $owner = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $this->ensureOwnerAccountCanBeDeleted->handle($owner);

            $owner->delete();
        }, 3);
    }
}
