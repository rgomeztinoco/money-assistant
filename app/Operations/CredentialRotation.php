<?php

namespace App\Operations;

use App\Actions\OpenClaw\BuildFinancialExport;
use App\Models\GmailConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CredentialRotation
{
    public function __construct(private BuildFinancialExport $buildFinancialExport) {}

    public function financialStateFingerprint(): string
    {
        $owner = User::query()->sole();

        return $this->buildFinancialExport->handle($owner)->digest;
    }

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
