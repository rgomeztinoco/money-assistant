<?php

namespace App\Actions\Monitoring;

use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Listeners\RecordOwnerLoginLockout;
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
        $isHealthy = $state === 'connected';

        return [
            'key' => 'gmail',
            'severity' => 'warning',
            'state' => $isHealthy ? 'healthy' : 'failed',
            'grace_seconds' => 900,
            'message' => $isHealthy
                ? 'Gmail synchronization is healthy.'
                : 'Gmail synchronization has been unhealthy for 15 minutes. Open Settings > Connections to reconnect or inspect the failure.',
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
                ? 'The oldest processing item has been stalled for 15 minutes. Inspect the worker and failed jobs.'
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
