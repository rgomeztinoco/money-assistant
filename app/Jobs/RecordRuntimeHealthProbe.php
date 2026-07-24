<?php

namespace App\Jobs;

use App\Operations\RuntimeHealth;
use App\RuntimeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordRuntimeHealthProbe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    public function uniqueId(): string
    {
        return 'runtime-health-probe';
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5];
    }

    public function handle(RuntimeHealth $runtimeHealth): void
    {
        $runtimeHealth->record(RuntimeService::Worker);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('The durable runtime health probe failed.', [
            'exception' => $exception,
        ]);
    }
}
