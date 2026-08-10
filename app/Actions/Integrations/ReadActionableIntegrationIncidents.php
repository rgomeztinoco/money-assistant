<?php

namespace App\Actions\Integrations;

use App\IntegrationWorkType;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\GmailMessageDiscovery;
use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ReadActionableIntegrationIncidents
{
    /**
     * @return list<array{
     *     type: string,
     *     incident_id: int,
     *     integration: string,
     *     work_type: string,
     *     work_id: string,
     *     failure_kind: string,
     *     error_code: string,
     *     state: string,
     *     replayable: bool,
     *     first_failed_at: string,
     *     next_attempt_at: string|null,
     *     affected_url: string
     * }>
     */
    public function handle(User $owner): array
    {
        $incidents = IntegrationIncident::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('visible_at', '<=', now())
            ->whereNull('acknowledged_at')
            ->whereNull('recovered_at')
            ->orderByRaw('parked_at IS NULL')
            ->orderBy('first_failed_at')
            ->get();

        if ($incidents->isEmpty()) {
            return [];
        }

        $gmailDiscoveries = GmailMessageDiscovery::query()
            ->whereHas('gmailConnection', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->whereIntegerInRaw(
                'id',
                $this->workIds($incidents, IntegrationWorkType::GmailMessage),
            )
            ->get(['id'])
            ->keyBy('id');
        $dailyExchangeRateSeedRequests = DailyExchangeRateSeedRequest::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereIntegerInRaw(
                'id',
                $this->workIds($incidents, IntegrationWorkType::DailyExchangeRateSeed),
            )
            ->get(['id', 'applicable_on'])
            ->keyBy('id');

        return array_values($incidents
            ->map(fn (IntegrationIncident $incident): array => [
                'type' => 'integration_incident',
                'incident_id' => $incident->id,
                'integration' => $incident->integration->value,
                'work_type' => $incident->work_type->value,
                'work_id' => $incident->work_id,
                'failure_kind' => $incident->failure_kind->value,
                'error_code' => $incident->last_error_code,
                'state' => $incident->parked_at === null ? 'retrying' : 'parked',
                'replayable' => $incident->parked_at !== null
                    && $incident->failure_kind->isTransient(),
                'first_failed_at' => $incident->first_failed_at->toIso8601String(),
                'next_attempt_at' => $incident->next_attempt_at?->toIso8601String(),
                'affected_url' => $this->affectedUrl(
                    $incident,
                    $gmailDiscoveries,
                    $dailyExchangeRateSeedRequests,
                ),
            ])
            ->all());
    }

    /**
     * @param  Collection<int, GmailMessageDiscovery>  $gmailDiscoveries
     * @param  Collection<int, DailyExchangeRateSeedRequest>  $dailyExchangeRateSeedRequests
     */
    private function affectedUrl(
        IntegrationIncident $incident,
        Collection $gmailDiscoveries,
        Collection $dailyExchangeRateSeedRequests,
    ): string {
        return match ($incident->work_type) {
            IntegrationWorkType::GmailSynchronization => route(
                'connections.edit',
                ['integration' => 'gmail'],
            ).'#gmail',
            IntegrationWorkType::GmailMessage => $this->gmailMessageUrl(
                $incident,
                $gmailDiscoveries,
            ),
            IntegrationWorkType::DailyExchangeRateSeed => $this->dailyExchangeRateUrl(
                $incident,
                $dailyExchangeRateSeedRequests,
            ),
            IntegrationWorkType::ReminderDelivery => route(
                'connections.edit',
                [
                    'integration' => 'openclaw',
                    'delivery' => $incident->work_id,
                ],
            ).'#openclaw',
        };
    }

    /** @param Collection<int, GmailMessageDiscovery> $gmailDiscoveries */
    private function gmailMessageUrl(
        IntegrationIncident $incident,
        Collection $gmailDiscoveries,
    ): string {
        $discovery = $gmailDiscoveries->get((int) $incident->work_id);

        return $discovery === null
            ? route('parser_profiles.index')
            : route('parser_profiles.source_messages.show', $discovery);
    }

    /** @param Collection<int, DailyExchangeRateSeedRequest> $dailyExchangeRateSeedRequests */
    private function dailyExchangeRateUrl(
        IntegrationIncident $incident,
        Collection $dailyExchangeRateSeedRequests,
    ): string {
        $request = $dailyExchangeRateSeedRequests->get((int) $incident->work_id);
        $applicableOn = $request?->applicable_on->toDateString();

        return route('daily_exchange_rates.index', [
            'date' => $applicableOn,
            'status' => 'attention',
        ]).($applicableOn === null ? '' : '#rate-request-'.$applicableOn);
    }

    /**
     * @param  Collection<int, IntegrationIncident>  $incidents
     * @return list<int>
     */
    private function workIds(
        Collection $incidents,
        IntegrationWorkType $workType,
    ): array {
        $workIds = [];

        foreach ($incidents as $incident) {
            if ($incident->work_type === $workType) {
                $workIds[] = (int) $incident->work_id;
            }
        }

        return $workIds;
    }
}
