<?php

namespace App\Operations;

use App\Models\GmailConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class CredentialRotation
{
    private const array NON_FINANCIAL_TABLES = [
        'cache',
        'cache_locks',
        'deployment_rehearsal_probes',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'passkeys',
        'runtime_health_checks',
        'sessions',
    ];

    private const array CREDENTIAL_COLUMNS = [
        'access_token',
        'password',
        'recovery_codes',
        'refresh_token',
        'remember_token',
    ];

    /** @throws JsonException */
    public function financialStateFingerprint(): string
    {
        $tables = collect(DB::select(<<<'SQL'
            SELECT tablename
            FROM pg_catalog.pg_tables
            WHERE schemaname = 'public'
            ORDER BY tablename
            SQL))
            ->pluck('tablename')
            ->reject(fn (string $table): bool => in_array($table, self::NON_FINANCIAL_TABLES, true));
        $state = [];

        foreach ($tables as $table) {
            $columns = array_values(array_diff(
                Schema::getColumnListing($table),
                self::CREDENTIAL_COLUMNS,
            ));
            $query = DB::table($table)->select($columns);

            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }

            $state[$table] = $query->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
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
