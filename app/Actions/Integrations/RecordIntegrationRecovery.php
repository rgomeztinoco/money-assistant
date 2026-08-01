<?php

namespace App\Actions\Integrations;

use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RecordIntegrationRecovery
{
    public function handle(
        User $owner,
        IntegrationService $integration,
        IntegrationWorkType $workType,
        string $workId,
    ): ?IntegrationIncident {
        return DB::transaction(function () use (
            $owner,
            $integration,
            $workType,
            $workId,
        ): ?IntegrationIncident {
            $incident = IntegrationIncident::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('integration', $integration)
                ->where('work_type', $workType)
                ->where('work_id', $workId)
                ->lockForUpdate()
                ->first();

            if ($incident === null || $incident->recovered_at !== null) {
                return $incident;
            }

            $incident->forceFill([
                'recovered_at' => now(),
                'next_attempt_at' => null,
                'parked_at' => null,
            ])->save();

            return $incident;
        });
    }
}
