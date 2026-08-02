<?php

namespace App\Jobs;

use App\Actions\Operations\RunCrashRecoveryFinancialRehearsal;
use App\Operations\DeploymentRehearsal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    public function handle(
        DeploymentRehearsal $deploymentRehearsal,
        RunCrashRecoveryFinancialRehearsal $runCrashRecoveryFinancialRehearsal,
    ): void {
        DB::transaction(function () use ($deploymentRehearsal, $runCrashRecoveryFinancialRehearsal): void {
            $holdMarker = $deploymentRehearsal->crashHoldMarker($this->probeId);

            $financialEffectRehearsalId = $deploymentRehearsal
                ->financialEffectRehearsalIdForProbe($this->probeId);

            if ($financialEffectRehearsalId !== null) {
                $runCrashRecoveryFinancialRehearsal->handle($financialEffectRehearsalId);
            }

            if (Storage::disk('local')->exists($holdMarker)) {
                Storage::disk('local')->put(
                    $deploymentRehearsal->crashStartedMarker($this->probeId),
                    now()->toIso8601String(),
                );
                sleep(max(0, (int) config('app.deployment_rehearsal_crash_hold_seconds')));
            }

            $deploymentRehearsal->complete($this->probeId);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A deployment rehearsal probe failed.', [
            'probe_id' => $this->probeId,
            'exception' => $exception,
        ]);
    }
}
