<?php

namespace App\Jobs;

use App\Operations\DeploymentRehearsal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompleteDeploymentRehearsalProbe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public string $probeId) {}

    public function uniqueId(): string
    {
        return $this->probeId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5];
    }

    public function handle(DeploymentRehearsal $deploymentRehearsal): void
    {
        $deploymentRehearsal->complete($this->probeId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A deployment rehearsal probe failed.', [
            'probe_id' => $this->probeId,
            'exception' => $exception,
        ]);
    }
}
