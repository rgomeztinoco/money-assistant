<?php

namespace App\Actions\Reporting;

use App\Models\DailyExchangeRateSeedRequest;
use Illuminate\Support\Facades\DB;

final class IsDailyExchangeRateStillRequired
{
    public function handle(DailyExchangeRateSeedRequest $seedRequest): bool
    {
        return DB::table('transactions')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->where('transactions.user_id', $seedRequest->user_id)
            ->whereDate('transactions.occurred_on', $seedRequest->applicable_on)
            ->whereNull('transactions.voided_at')
            ->whereNotNull('users.reporting_currency')
            ->whereColumn('transactions.currency', '!=', 'users.reporting_currency')
            ->exists();
    }
}
