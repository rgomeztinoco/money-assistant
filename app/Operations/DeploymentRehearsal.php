<?php

namespace App\Operations;

use App\DeploymentRehearsalProbeKind;
use App\Jobs\CompleteDeploymentRehearsalProbe;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

final class DeploymentRehearsal
{
    public function prepare(string $rehearsalId, bool $holdForCrash = false): string
    {
        $queuedProbeId = (string) Str::uuid();

        DB::table('deployment_rehearsal_probes')->insert([
            [
                'id' => $queuedProbeId,
                'rehearsal_id' => $rehearsalId,
                'kind' => DeploymentRehearsalProbeKind::Queued->value,
                'due_at' => now(),
                'completed_at' => null,
                'completion_count' => 0,
                'requires_financial_effect' => $holdForCrash,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'rehearsal_id' => $rehearsalId,
                'kind' => DeploymentRehearsalProbeKind::Scheduled->value,
                'due_at' => now(),
                'completed_at' => null,
                'completion_count' => 0,
                'requires_financial_effect' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if ($holdForCrash) {
            Storage::disk('local')->put(
                $this->crashHoldMarker($queuedProbeId),
                $rehearsalId,
            );
        }

        CompleteDeploymentRehearsalProbe::dispatch($queuedProbeId);

        return $queuedProbeId;
    }

    public function dispatchDueScheduledProbes(): void
    {
        DB::table('deployment_rehearsal_probes')
            ->where('kind', DeploymentRehearsalProbeKind::Scheduled->value)
            ->where('due_at', '<=', now())
            ->whereNull('completed_at')
            ->orderBy('due_at')
            ->limit(100)
            ->pluck('id')
            ->each(fn ($probeId) => CompleteDeploymentRehearsalProbe::dispatch((string) $probeId));
    }

    public function complete(string $probeId): void
    {
        DB::table('deployment_rehearsal_probes')
            ->where('id', $probeId)
            ->whereNull('completed_at')
            ->update([
                'completed_at' => now(),
                'completion_count' => DB::raw('completion_count + 1'),
                'updated_at' => now(),
            ]);
    }

    public function crashHoldMarker(string $probeId): string
    {
        return "deployment-crash-rehearsals/{$probeId}.hold";
    }

    public function crashStartedMarker(string $probeId): string
    {
        return "deployment-crash-rehearsals/{$probeId}.started";
    }

    public function financialEffectRehearsalIdForProbe(string $probeId): ?string
    {
        $probe = DB::table('deployment_rehearsal_probes')
            ->where('id', $probeId)
            ->first(['rehearsal_id', 'requires_financial_effect']);

        if ($probe === null) {
            throw new LogicException('The deployment rehearsal probe does not exist.');
        }

        return $probe->requires_financial_effect
            ? (string) $probe->rehearsal_id
            : null;
    }

    public function isComplete(string $rehearsalId): bool
    {
        $probes = DB::table('deployment_rehearsal_probes')
            ->where('rehearsal_id', $rehearsalId)
            ->get(['kind', 'completed_at', 'completion_count', 'requires_financial_effect']);

        $probesAreComplete = $probes->count() === 2
            && $probes->pluck('kind')->sort()->values()->all() === [
                DeploymentRehearsalProbeKind::Queued->value,
                DeploymentRehearsalProbeKind::Scheduled->value,
            ]
            && $probes->every(
                fn (object $probe): bool => $probe->completed_at !== null
                    && $probe->completion_count === 1,
            );

        if (! $probesAreComplete) {
            return false;
        }

        if (! $probes->contains(fn (object $probe): bool => $probe->requires_financial_effect)) {
            return true;
        }

        $transaction = Transaction::query()
            ->where('deployment_rehearsal_id', $rehearsalId)
            ->first();

        return $transaction?->voided_at !== null;
    }
}
