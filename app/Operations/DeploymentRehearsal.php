<?php

namespace App\Operations;

use App\DeploymentRehearsalProbeKind;
use App\Jobs\CompleteDeploymentRehearsalProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeploymentRehearsal
{
    public function prepare(string $rehearsalId): void
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
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        CompleteDeploymentRehearsalProbe::dispatch($queuedProbeId);
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

    public function isComplete(string $rehearsalId): bool
    {
        $probes = DB::table('deployment_rehearsal_probes')
            ->where('rehearsal_id', $rehearsalId)
            ->get(['kind', 'completed_at', 'completion_count']);

        return $probes->count() === 2
            && $probes->pluck('kind')->sort()->values()->all() === [
                DeploymentRehearsalProbeKind::Queued->value,
                DeploymentRehearsalProbeKind::Scheduled->value,
            ]
            && $probes->every(
                fn (object $probe): bool => $probe->completed_at !== null
                    && $probe->completion_count === 1,
            );
    }
}
