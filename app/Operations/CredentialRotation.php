<?php

namespace App\Operations;

use App\Models\GmailConnection;
use Illuminate\Support\Facades\DB;

final class CredentialRotation
{
    public function rewrapIntegrationCredentials(): int
    {
        return DB::transaction(function (): int {
            $connections = GmailConnection::query()
                ->select(['id', 'access_token', 'refresh_token'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($connections as $connection) {
                $connection->timestamps = false;
                $connection->forceFill([
                    'access_token' => $connection->access_token,
                    'refresh_token' => $connection->refresh_token,
                ])->saveQuietly();
            }

            return $connections->count();
        });
    }
}
