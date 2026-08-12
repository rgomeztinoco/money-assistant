<?php

namespace App\Actions\Dashboard;

use App\Actions\Integrations\ReadActionableIntegrationIncidents;
use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Actions\NotificationIngestion\ReadParserProfileHealthSummary;
use App\Models\User;

final class ReadOperatingStatus
{
    public function __construct(
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
        private ReadParserProfileHealthSummary $readParserProfileHealthSummary,
        private ReadActionableIntegrationIncidents $readActionableIntegrationIncidents,
    ) {}

    /**
     * @return array{
     *     summary: array{
     *         gmail: string,
     *         parser_profiles: array{healthy_count: int, degraded_count: int}
     *     },
     *     exceptions: list<array<string, bool|int|string|null>>
     * }
     */
    public function handle(User $owner): array
    {
        $gmail = $this->readGmailConnectionStatus->handle($owner);
        $parserProfiles = $this->readParserProfileHealthSummary->handle($owner);
        $integrationIncidents = collect($this->readActionableIntegrationIncidents->handle($owner))
            ->reject(fn (array $incident): bool => $incident['integration'] === 'openclaw')
            ->values()
            ->all();
        $exceptions = [];

        foreach ($parserProfiles['alerts'] as $alert) {
            $exceptions[] = [
                'type' => $alert['kind'] === 'security'
                    ? 'parser_security'
                    : 'parser_drift',
                'profile_id' => $alert['profile_id'],
                'profile_name' => $alert['profile_name'],
                'count' => $alert['count'],
            ];
        }

        if ($gmail['state'] !== 'connected') {
            $exceptions[] = [
                'type' => 'gmail_connection',
                'state' => $gmail['state'],
            ];
        }

        foreach ($integrationIncidents as $integrationIncident) {
            $exceptions[] = $integrationIncident;
        }

        return [
            'summary' => [
                'gmail' => $gmail['state'],
                'parser_profiles' => [
                    'healthy_count' => $parserProfiles['healthy_count'],
                    'degraded_count' => $parserProfiles['degraded_count'],
                ],
            ],
            'exceptions' => $exceptions,
        ];
    }
}
