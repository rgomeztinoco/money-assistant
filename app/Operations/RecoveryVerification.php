<?php

namespace App\Operations;

use App\Models\GmailConnection;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class RecoveryVerification
{
    public const int FORMAT_VERSION = 1;

    /**
     * @return array{
     *     format_version: int,
     *     record_counts: array<string, int>,
     *     queue: array{pending: int, reserved: int, failed: int},
     *     integrations: array{gmail_connections: int}
     * }
     */
    public function inventory(): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'record_counts' => $this->recordCounts(),
            'queue' => $this->queueState(),
            'integrations' => [
                'gmail_connections' => GmailConnection::query()->count(),
            ],
        ];
    }

    /**
     * @param  array{
     *     format_version?: mixed,
     *     record_counts?: mixed,
     *     queue?: mixed,
     *     integrations?: mixed
     * }  $expectedInventory
     * @return list<string>
     */
    public function verify(array $expectedInventory, string $ownerPassword): array
    {
        if (($expectedInventory['format_version'] ?? null) !== self::FORMAT_VERSION) {
            return ['The backup application inventory format is unsupported.'];
        }

        $failures = [];
        $actualInventory = $this->inventory();

        if (($expectedInventory['record_counts'] ?? null) !== $actualInventory['record_counts']) {
            $failures[] = 'Restored record counts do not match the backup inventory.';
        }

        if (($expectedInventory['queue'] ?? null) !== $actualInventory['queue']) {
            $failures[] = 'Restored queue state does not match the backup inventory.';
        }

        if (($expectedInventory['integrations'] ?? null) !== $actualInventory['integrations']) {
            $failures[] = 'Restored integration state does not match the backup inventory.';
        }

        $owner = User::query()->first();

        if (
            User::query()->count() !== 1
            || $owner === null
            || ! Auth::guard()->validate([
                'email' => $owner->email,
                'password' => $ownerPassword,
            ])
        ) {
            $failures[] = 'Owner Account authentication failed.';
        }

        try {
            GmailConnection::query()
                ->orderBy('id')
                ->get(['id', 'access_token', 'refresh_token'])
                ->each(function (GmailConnection $connection): void {
                    if ($connection->access_token === '' || $connection->refresh_token === '') {
                        throw new \RuntimeException('An integration credential is empty.');
                    }
                });
        } catch (Throwable) {
            $failures[] = 'Restored integration credentials could not be decrypted.';
        }

        return $failures;
    }

    /** @return array<string, int> */
    private function recordCounts(): array
    {
        return collect(Schema::getTableListing())
            ->sort()
            ->mapWithKeys(
                fn (string $table): array => [
                    Str::afterLast($table, '.') => DB::table($table)->count(),
                ],
            )
            ->all();
    }

    /** @return array{pending: int, reserved: int, failed: int} */
    private function queueState(): array
    {
        return [
            'pending' => DB::table('jobs')->whereNull('reserved_at')->count(),
            'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'failed' => DB::table('failed_jobs')->count(),
        ];
    }
}
