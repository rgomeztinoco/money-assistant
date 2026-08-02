<?php

namespace App\Console\Commands;

use App\Actions\Integrations\ReplayIntegrationIncident;
use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\IntegrationIncident;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:rehearse-manual-replay')]
#[Description('Exercise a parked incident manual replay without changing production state')]
class RehearseManualReplay extends Command
{
    public function handle(ReplayIntegrationIncident $replayIntegrationIncident): int
    {
        if (config('queue.default') !== 'database') {
            $this->components->error('The manual replay rehearsal requires the database queue.');

            return self::FAILURE;
        }

        $owner = User::query()->oldest('id')->first();
        if ($owner === null) {
            $this->components->error('The target deployment has no owner account.');

            return self::FAILURE;
        }

        $counts = [
            'incidents' => IntegrationIncident::query()->count(),
            'jobs' => DB::table('jobs')->count(),
            'reminders' => Reminder::query()->count(),
            'deliveries' => ReminderDelivery::query()->count(),
        ];

        DB::beginTransaction();
        try {
            $rehearsalPassed = $this->exerciseReplay(
                $owner,
                $replayIntegrationIncident,
                $counts['jobs'],
            );
        } finally {
            DB::rollBack();
        }

        $stateIsUnchanged = $counts === [
            'incidents' => IntegrationIncident::query()->count(),
            'jobs' => DB::table('jobs')->count(),
            'reminders' => Reminder::query()->count(),
            'deliveries' => ReminderDelivery::query()->count(),
        ];

        if (! $rehearsalPassed || ! $stateIsUnchanged) {
            $this->components->error('The manual replay failed or changed production state.');

            return self::FAILURE;
        }

        $this->line('MANUAL_REPLAY_REHEARSAL outcome=passed');

        return self::SUCCESS;
    }

    private function exerciseReplay(
        User $owner,
        ReplayIntegrationIncident $replayIntegrationIncident,
        int $initialJobCount,
    ): bool {
        $deliveryId = Str::uuid()->toString();
        $sourceIdentity = 'production-trust:manual-replay:'.$deliveryId;
        $reminder = Reminder::query()->create([
            'user_id' => $owner->id,
            'subject' => 'Production trust manual replay',
            'scheduled_for' => now(),
        ]);
        $delivery = ReminderDelivery::query()->create([
            'id' => $deliveryId,
            'reminder_id' => $reminder->id,
            'scheduled_for' => now(),
            'occurred_at' => now(),
            'terminal_at' => now(),
            'terminal_reason' => 'retry_exhausted',
            'last_error_code' => 'production_trust_rehearsal',
        ]);
        $incident = IntegrationIncident::query()->create([
            'user_id' => $owner->id,
            'integration' => IntegrationService::OpenClaw,
            'work_type' => IntegrationWorkType::ReminderDelivery,
            'work_id' => $delivery->id,
            'source_identity' => $sourceIdentity,
            'failure_kind' => IntegrationFailureKind::Transient,
            'last_error_code' => 'production_trust_rehearsal',
            'attempt_count' => 3,
            'first_failed_at' => now()->subDay(),
            'last_failed_at' => now(),
            'visible_at' => now()->subMinutes(15),
            'retry_until' => now(),
            'parked_at' => now(),
        ]);

        $replayedIncident = $replayIntegrationIncident->handle($owner, $incident->id);
        $replayedDelivery = $delivery->fresh();

        if ($replayedDelivery === null) {
            return false;
        }

        return $replayedIncident->replay_count === 1
            && $replayedIncident->source_identity === $sourceIdentity
            && $replayedIncident->parked_at === null
            && $replayedDelivery->terminal_at === null
            && $replayedDelivery->queued_at !== null
            && DB::table('jobs')->count() === $initialJobCount + 1;
    }
}
