<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailRequestFailed;
use App\Models\GmailConnection;
use Illuminate\Support\Facades\DB;

final class CompleteGmailAuthorization
{
    public function __construct(private Gmail $gmail) {}

    public function handle(string $code): GmailConnection
    {
        $authorization = $this->gmail->authorize($code);

        if ($authorization->grantedScopes !== [Gmail::READ_ONLY_SCOPE]) {
            throw GmailRequestFailed::authorization();
        }

        return DB::transaction(function () use ($authorization): GmailConnection {
            $connection = GmailConnection::query()
                ->lockForUpdate()
                ->first() ?? new GmailConnection;

            if ($connection->exists
                && $connection->gmail_account_identity !== $authorization->accountIdentity) {
                throw GmailRequestFailed::authorization();
            }

            $connection->fill([
                'gmail_account_identity' => $authorization->accountIdentity,
                'access_token' => $authorization->accessToken,
                'refresh_token' => $authorization->refreshToken,
                'access_token_expires_at' => $authorization->accessTokenExpiresAt,
                'granted_scopes' => $authorization->grantedScopes,
                'connected_at' => $connection->connected_at ?? now(),
                'last_successful_check_at' => now(),
                'last_check_failed_at' => null,
                'reauthorization_required_at' => null,
                'last_error_code' => null,
                'history_id' => $connection->history_id ?? $authorization->historyId,
            ])->save();

            return $connection;
        });
    }
}
