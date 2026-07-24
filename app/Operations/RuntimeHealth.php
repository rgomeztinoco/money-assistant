<?php

namespace App\Operations;

use App\Jobs\RecordRuntimeHealthProbe;
use App\RuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RuntimeHealth
{
    private const int MAXIMUM_AGE_IN_SECONDS = 120;

    public function dispatchProbe(): void
    {
        $this->record(RuntimeService::Scheduler);

        RecordRuntimeHealthProbe::dispatch();
    }

    public function record(RuntimeService $service): void
    {
        DB::table('runtime_health_checks')->upsert(
            [
                [
                    'service' => $service->value,
                    'last_seen_at' => now(),
                ],
            ],
            ['service'],
            ['last_seen_at'],
        );
    }

    public function isFresh(RuntimeService $service): bool
    {
        $lastSeenAt = DB::table('runtime_health_checks')
            ->where('service', $service->value)
            ->value('last_seen_at');

        return $lastSeenAt !== null
            && CarbonImmutable::parse($lastSeenAt)
                ->isAfter(now()->subSeconds(self::MAXIMUM_AGE_IN_SECONDS));
    }
}
