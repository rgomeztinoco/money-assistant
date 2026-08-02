<?php

namespace App\Console\Commands;

use App\Models\GmailConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:credentials:rewrap')]
#[Description('Re-encrypt retained integration credentials with the current application key')]
class RewrapIntegrationCredentials extends Command
{
    public function handle(): int
    {
        $rewrappedConnections = 0;

        GmailConnection::query()
            ->select(['id', 'access_token', 'refresh_token'])
            ->orderBy('id')
            ->chunkById(100, function ($connections) use (&$rewrappedConnections): void {
                DB::transaction(function () use ($connections, &$rewrappedConnections): void {
                    foreach ($connections as $connection) {
                        $connection->forceFill([
                            'access_token' => $connection->access_token,
                            'refresh_token' => $connection->refresh_token,
                        ])->saveQuietly();

                        $rewrappedConnections++;
                    }
                });
            });

        $this->components->info("Re-encrypted {$rewrappedConnections} Gmail connection credential set(s).");

        return self::SUCCESS;
    }
}
