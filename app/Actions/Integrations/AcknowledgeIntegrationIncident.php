<?php

namespace App\Actions\Integrations;

use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AcknowledgeIntegrationIncident
{
    public function handle(User $owner, int $incidentId): IntegrationIncident
    {
        return DB::transaction(function () use ($owner, $incidentId): IntegrationIncident {
            $incident = IntegrationIncident::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($incidentId);

            if ($incident->recovered_at === null && $incident->acknowledged_at === null) {
                $incident->forceFill(['acknowledged_at' => now()])->save();
            }

            return $incident;
        });
    }
}
