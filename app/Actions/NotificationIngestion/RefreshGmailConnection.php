<?php

namespace App\Actions\NotificationIngestion;

use App\Contracts\Gmail;
use App\Integrations\Gmail\GmailReauthorizationRequired;
use App\Models\GmailConnection;
use Illuminate\Support\Facades\DB;

final class RefreshGmailConnection
{
    public function __construct(private Gmail $gmail) {}

    public function handle(GmailConnection $connection): GmailConnection
    {
        $refreshToken = DB::transaction(function () use ($connection): string {
            $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($current->ingestionIsPaused()) {
                throw new GmailReauthorizationRequired;
            }

            return $current->refresh_token;
        });

        try {
            $access = $this->gmail->refresh($refreshToken);
        } catch (GmailReauthorizationRequired) {
            $requiresReauthorization = DB::transaction(function () use ($connection, $refreshToken): bool {
                $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

                if ($current->refresh_token !== $refreshToken) {
                    return false;
                }

                $current->fill([
                    'reauthorization_required_at' => now(),
                    'last_error_code' => GmailConnection::ERROR_REFRESH_TOKEN_REJECTED,
                ])->save();

                return true;
            });

            if ($requiresReauthorization) {
                throw new GmailReauthorizationRequired;
            }

            return GmailConnection::query()->findOrFail($connection->id);
        }

        return DB::transaction(function () use ($connection, $refreshToken, $access): GmailConnection {
            $current = GmailConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($current->refresh_token !== $refreshToken || $current->ingestionIsPaused()) {
                return $current;
            }

            $current->fill([
                'access_token' => $access->accessToken,
                'access_token_expires_at' => $access->accessTokenExpiresAt,
                'last_error_code' => null,
            ])->save();

            return $current;
        });
    }
}
