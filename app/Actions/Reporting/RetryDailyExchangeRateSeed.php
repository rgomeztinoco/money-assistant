<?php

namespace App\Actions\Reporting;

use App\Jobs\SeedDailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetryDailyExchangeRateSeed
{
    public function handle(User $owner, int $seedRequestId): DailyExchangeRateSeedRequest
    {
        $shouldDispatch = false;
        $seedRequest = DB::transaction(function () use ($owner, $seedRequestId, &$shouldDispatch): DailyExchangeRateSeedRequest {
            $seedRequest = DailyExchangeRateSeedRequest::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($seedRequestId);

            if ($seedRequest->retrieval_failed_at === null) {
                return $seedRequest;
            }

            $seedRequest->forceFill([
                'attempt_count' => 0,
                'missing_observation_count' => 0,
                'transport_failure_count' => 0,
                'next_attempt_at' => null,
                'queued_at' => now(),
                'claimed_at' => null,
                'last_attempted_at' => null,
                'retrieval_failed_at' => null,
                'last_error_code' => null,
            ])->save();
            $shouldDispatch = true;

            return $seedRequest;
        });

        if ($shouldDispatch) {
            SeedDailyExchangeRate::dispatch($seedRequest->id);
        }

        return $seedRequest;
    }
}
