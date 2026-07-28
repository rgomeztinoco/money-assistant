<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CheckGmailConnection
{
    public function __construct(
        private RefreshGmailConnection $refreshConnection,
        private Gmail $gmail,
    ) {}

    public function handle(User $owner): ?GmailConnection
    {
        $connection = GmailConnection::query()->whereBelongsTo($owner, 'owner')->first();

        if ($connection === null) {
            return null;
        }

        try {
            $connection = $this->refreshConnection->handle($connection);
            $profile = $this->gmail->profile($connection->access_token);
        } catch (GmailRequestFailed) {
            $this->recordFailedCheck($connection);

            throw GmailRequestFailed::profile();
        }

        if ($profile->accountIdentity !== $connection->gmail_account_identity) {
            if ($this->recordAccountMismatch($connection)) {
                throw new GmailReauthorizationRequired;
            }

            return GmailConnection::query()->findOrFail($connection->id);
        }

        return DB::transaction(function () use ($connection): GmailConnection {
            $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($current->access_token !== $connection->access_token || $current->ingestionIsPaused()) {
                return $current;
            }

            $current->fill([
                'last_successful_check_at' => now(),
                'last_check_failed_at' => null,
                'last_error_code' => null,
            ])->save();

            return $current;
        });
    }

    private function recordFailedCheck(GmailConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($current->ingestionIsPaused()
                || $current->refresh_token !== $connection->refresh_token
                || $current->access_token !== $connection->access_token) {
                return;
            }

            $current->fill([
                'last_check_failed_at' => now(),
                'last_error_code' => GmailConnection::ERROR_CHECK_FAILED,
            ])->save();
        });
    }

    private function recordAccountMismatch(GmailConnection $connection): bool
    {
        return DB::transaction(function () use ($connection): bool {
            $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($current->refresh_token !== $connection->refresh_token
                || $current->access_token !== $connection->access_token) {
                return false;
            }

            $current->fill([
                'last_check_failed_at' => now(),
                'reauthorization_required_at' => now(),
                'last_error_code' => GmailConnection::ERROR_GMAIL_ACCOUNT_MISMATCH,
            ])->save();

            return true;
        });
    }
}
