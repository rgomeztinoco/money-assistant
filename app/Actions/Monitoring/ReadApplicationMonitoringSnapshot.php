<?php

namespace App\Actions\Monitoring;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\IntegrationService;
use App\Listeners\RecordOwnerLoginLockout;
use App\Models\IntegrationIncident;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ReadApplicationMonitoringSnapshot
{
    public function __construct(
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     severity: 'warning'|'critical',
     *     state: 'healthy'|'failed',
     *     grace_seconds: int,
     *     message: string
     * }>
     */
    public function handle(): array
    {
        return [
            $this->gmailStatus(),
            $this->openClawDeliveryStatus(),
            $this->oldestProcessingItemStatus(),
            $this->repeatedLoginStatus(),
        ];
    }

    /** @return array{key: string, severity: 'warning', state: 'healthy'|'failed', grace_seconds: int, message: string} */
    private function gmailStatus(): array
    {
        $owner = User::query()->oldest('id')->first();
        $state = $owner === null
            ? 'disconnected'
            : $this->readGmailConnectionStatus->handle($owner)['state'];
        $hasVisibleFailure = IntegrationIncident::query()
            ->where('integration', IntegrationService::Gmail)
            ->where('visible_at', '<=', now())
            ->whereNull('recovered_at')
            ->exists();
        $isHealthy = $state === 'connected' && ! $hasVisibleFailure;

        return [
            'key' => 'gmail',
            'severity' => 'warning',
            'state' => $isHealthy ? 'healthy' : 'failed',
            'grace_seconds' => $hasVisibleFailure ? 0 : 900,
            'message' => match (true) {
                $isHealthy => 'Gmail synchronization is healthy.',
                $hasVisibleFailure => 'Gmail synchronization or message processing has failed for 15 minutes. Inspect the Dashboard incident before replaying parked work.',
                default => 'Gmail synchronization has been unhealthy for 15 minutes. Open Settings > Connections to reconnect or inspect the failure.',
            },
        ];
    }

    /** @return array{key: string, severity: 'warning', state: 'healthy'|'failed', grace_seconds: int, message: string} */
    private function openClawDeliveryStatus(): array
    {
        $hasVisibleFailure = IntegrationIncident::query()
            ->where('integration', IntegrationService::OpenClaw)
            ->where('visible_at', '<=', now())
            ->whereNull('recovered_at')
            ->exists();

        return [
            'key' => 'openclaw_delivery',
            'severity' => 'warning',
            'state' => $hasVisibleFailure ? 'failed' : 'healthy',
            'grace_seconds' => 0,
            'message' => $hasVisibleFailure
                ? 'OpenClaw outbound delivery has been unhealthy for 15 minutes. Inspect the Dashboard incident and replay parked work after recovery.'
                : 'OpenClaw outbound delivery is healthy.',
        ];
    }

    /** @return array{key: string, severity: 'warning', state: 'healthy'|'failed', grace_seconds: int, message: string} */
    private function oldestProcessingItemStatus(): array
    {
        $queueTable = (string) config('queue.connections.database.table', 'jobs');
        $stalledBefore = now()->subMinutes(15)->getTimestamp();
        $isStalled = DB::table($queueTable)
            ->where(function (Builder $query) use ($stalledBefore): void {
                $query
                    ->where('reserved_at', '<=', $stalledBefore)
                    ->orWhere(function (Builder $query) use ($stalledBefore): void {
                        $query
                            ->whereNull('reserved_at')
                            ->where('available_at', '<=', $stalledBefore);
                    });
            })
            ->exists();

        return [
            'key' => 'oldest_processing_item',
            'severity' => 'warning',
            'state' => $isStalled ? 'failed' : 'healthy',
            'grace_seconds' => 0,
            'message' => $isStalled
                ? 'The oldest processing item has been stalled for 15 minutes. Inspect the worker and the Dashboard incident before replaying work.'
                : 'Queued processing is current.',
        ];
    }

    /** @return array{key: string, severity: 'critical', state: 'healthy'|'failed', grace_seconds: int, message: string} */
    private function repeatedLoginStatus(): array
    {
        $hasLockout = Cache::has(RecordOwnerLoginLockout::CACHE_KEY);

        return [
            'key' => 'repeated_login',
            'severity' => 'critical',
            'state' => $hasLockout ? 'failed' : 'healthy',
            'grace_seconds' => 0,
            'message' => $hasLockout
                ? 'Repeated Owner Account login failures triggered a lockout. Review authentication logs and rotate credentials if the attempts were not yours.'
                : 'Owner Account login activity is healthy.',
        ];
    }
}
