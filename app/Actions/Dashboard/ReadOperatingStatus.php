<?php

namespace App\Actions\Dashboard;

use App\Actions\Integrations\ReadActionableIntegrationIncidents;
use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Models\ParserProfile;
use App\Models\User;

final class ReadOperatingStatus
{
    public function __construct(
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
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
        $enabledParserProfileCount = ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNotNull('enabled_at')
            ->count();
        $integrationIncidents = collect($this->readActionableIntegrationIncidents->handle($owner))
            ->reject(fn (array $incident): bool => $incident['integration'] === 'openclaw')
            ->values()
            ->all();
        $exceptions = [];

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
                    'healthy_count' => $enabledParserProfileCount,
                    'degraded_count' => 0,
                ],
            ],
            'exceptions' => $exceptions,
        ];
    }
}
