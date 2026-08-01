<?php

namespace App\Actions\Reporting;

use App\Actions\Integrations\ReplayIntegrationIncident;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Jobs\SeedDailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetryDailyExchangeRateSeed
{
    public function __construct(
        private ReplayIntegrationIncident $replayIntegrationIncident,
    ) {}

    public function handle(User $owner, int $seedRequestId): DailyExchangeRateSeedRequest
    {
        $incident = $owner->integrationIncidents()
            ->where('integration', IntegrationService::Bcrp)
            ->where('work_type', IntegrationWorkType::DailyExchangeRateSeed)
            ->where('work_id', (string) $seedRequestId)
            ->first();

        if ($incident?->parked_at !== null) {
            $this->replayIntegrationIncident->handle($owner, $incident->id);

            return DailyExchangeRateSeedRequest::query()->findOrFail($seedRequestId);
        }

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
