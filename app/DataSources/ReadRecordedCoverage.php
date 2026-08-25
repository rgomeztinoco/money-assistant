<?php

namespace App\DataSources;

use App\Models\GmailConnection;
use App\Models\StatementImport;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ReadRecordedCoverage
{
    /**
     * @return array{
     *     status: 'recorded'|'partially_verified'|'verified',
     *     gmail_last_checked_at: string|null,
     *     verified_periods: list<array{id: int, period_start: string, period_end: string, instrument_label: string}>
     * }
     */
    public function handle(
        User $owner,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
    ): array {
        $connection = GmailConnection::query()
            ->whereBelongsTo($owner, 'owner')
            ->first(['id', 'last_successful_sync_at', 'last_successful_check_at']);
        $verifiedImports = StatementImport::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereDate('period_start', '<=', $dateTo->toDateString())
            ->whereDate('period_end', '>=', $dateFrom->toDateString())
            ->oldest('period_start')
            ->get(['id', 'period_start', 'period_end', 'instrument_label']);
        $spanningVerifiedImportIds = $verifiedImports->filter(
            fn (StatementImport $statementImport): bool => $statementImport->period_start->lessThanOrEqualTo($dateFrom)
                && $statementImport->period_end->greaterThanOrEqualTo($dateTo),
        )->pluck('id');
        $hasUnverifiedRecordedTransactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->whereDoesntHave(
                'statementMovement',
                fn ($query) => $query->whereIn('statement_import_id', $spanningVerifiedImportIds),
            )
            ->exists();
        $fullyVerified = $spanningVerifiedImportIds->isNotEmpty()
            && ! $hasUnverifiedRecordedTransactions;
        $gmailLastCheckedAt = $connection?->last_successful_check_at;

        if ($connection?->last_successful_sync_at?->greaterThan($gmailLastCheckedAt) === true) {
            $gmailLastCheckedAt = $connection->last_successful_sync_at;
        }

        return [
            'status' => $fullyVerified
                ? 'verified'
                : ($verifiedImports->isEmpty() ? 'recorded' : 'partially_verified'),
            'gmail_last_checked_at' => $gmailLastCheckedAt?->toIso8601String(),
            'verified_periods' => array_values($verifiedImports->map(fn (StatementImport $statementImport): array => [
                'id' => $statementImport->id,
                'period_start' => $statementImport->period_start->toDateString(),
                'period_end' => $statementImport->period_end->toDateString(),
                'instrument_label' => $statementImport->instrument_label,
            ])->all()),
        ];
    }
}
