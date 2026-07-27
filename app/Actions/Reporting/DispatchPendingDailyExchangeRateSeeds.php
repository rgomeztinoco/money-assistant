<?php

namespace App\Actions\Reporting;

use App\Jobs\SeedDailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DispatchPendingDailyExchangeRateSeeds
{
    public function handle(): void
    {
        /** @var Collection<int, int> $seedRequestIds */
        $seedRequestIds = DB::transaction(function (): Collection {
            $seedRequests = DailyExchangeRateSeedRequest::query()
                ->whereNull('completed_at')
                ->whereNull('owner_entry_required_at')
                ->whereNull('retrieval_failed_at')
                ->where(fn ($query) => $query
                    ->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query
                    ->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', now()->subMinutes(2)))
                ->where(fn ($query) => $query
                    ->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinute()))
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            $seedRequests->each(function (DailyExchangeRateSeedRequest $seedRequest): void {
                $seedRequest->forceFill(['queued_at' => now()])->save();
            });

            return $seedRequests->pluck('id');
        });

        $seedRequestIds->each(
            fn (int $seedRequestId) => SeedDailyExchangeRate::dispatch($seedRequestId),
        );
    }
}
