<?php

namespace App\Actions\Reporting;

use App\Actions\Reminders\ResolveReminder;
use App\Models\DailyExchangeRateSeedRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DiscoverMissingDailyExchangeRates
{
    public function __construct(
        private IsDailyExchangeRateStillRequired $isDailyExchangeRateStillRequired,
        private ResolveReminder $resolveReminder,
    ) {}

    public function handle(): void
    {
        $this->completeUnneededSeedRequests();

        $missingRates = DB::table('transactions')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->leftJoin('daily_exchange_rates', function ($join): void {
                $join->on('daily_exchange_rates.user_id', '=', 'transactions.user_id')
                    ->on('daily_exchange_rates.applicable_on', '=', 'transactions.occurred_on');
            })
            ->whereNull('transactions.voided_at')
            ->whereNotNull('users.reporting_currency')
            ->whereColumn('transactions.currency', '!=', 'users.reporting_currency')
            ->whereNull('daily_exchange_rates.id')
            ->select(['transactions.user_id', 'transactions.occurred_on'])
            ->distinct()
            ->orderBy('transactions.user_id')
            ->orderBy('transactions.occurred_on')
            ->cursor();

        foreach ($missingRates as $missingRate) {
            DB::transaction(function () use ($missingRate): void {
                DailyExchangeRateSeedRequest::query()->insertOrIgnore([
                    'user_id' => $missingRate->user_id,
                    'applicable_on' => $missingRate->occurred_on,
                    'resolution_idempotency_key' => Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $seedRequest = DailyExchangeRateSeedRequest::query()
                    ->where('user_id', $missingRate->user_id)
                    ->whereDate('applicable_on', $missingRate->occurred_on)
                    ->lockForUpdate()
                    ->first();

                if ($seedRequest === null || $seedRequest->completed_at === null) {
                    return;
                }

                $seedRequest->forceFill([
                    'attempt_count' => 0,
                    'missing_observation_count' => 0,
                    'transport_failure_count' => 0,
                    'next_attempt_at' => null,
                    'queued_at' => null,
                    'claimed_at' => null,
                    'last_attempted_at' => null,
                    'completed_at' => null,
                    'owner_entry_required_at' => null,
                    'retrieval_failed_at' => null,
                    'last_error_code' => null,
                    'reminder_id' => null,
                    'resolution_idempotency_key' => Str::uuid(),
                ])->save();
            });
        }
    }

    private function completeUnneededSeedRequests(): void
    {
        foreach (DailyExchangeRateSeedRequest::query()
            ->whereNull('completed_at')
            ->orderBy('id')
            ->cursor() as $seedRequest) {
            if ($this->isDailyExchangeRateStillRequired->handle($seedRequest)) {
                continue;
            }

            DB::transaction(function () use ($seedRequest): void {
                $current = DailyExchangeRateSeedRequest::query()
                    ->with(['owner', 'reminder'])
                    ->lockForUpdate()
                    ->find($seedRequest->id);

                if ($current === null
                    || $current->completed_at !== null
                    || $this->isDailyExchangeRateStillRequired->handle($current)) {
                    return;
                }

                $current->forceFill([
                    'completed_at' => now(),
                    'owner_entry_required_at' => null,
                    'retrieval_failed_at' => null,
                    'next_attempt_at' => null,
                    'queued_at' => null,
                    'claimed_at' => null,
                    'last_error_code' => 'no_longer_required',
                ])->save();

                if ($current->reminder_id === null || $current->reminder?->resolved_at !== null) {
                    return;
                }

                $this->resolveReminder->handle(
                    owner: $current->owner,
                    reminderId: $current->reminder_id,
                    domainAction: 'daily_exchange_rate.no_longer_required',
                    idempotencyKey: $current->resolution_idempotency_key,
                );
            });
        }
    }
}
