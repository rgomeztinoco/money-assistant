<?php

namespace App\Actions\Integrations;

use App\GmailSynchronizationType;
use App\IntegrationFailureKind;
use App\IntegrationWorkType;
use App\Jobs\DeliverReminder;
use App\Jobs\ProcessGmailMessage;
use App\Jobs\SeedDailyExchangeRate;
use App\Jobs\SynchronizeGmail;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\IntegrationIncident;
use App\Models\ReminderDelivery;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReplayIntegrationIncident
{
    public function handle(User $owner, int $incidentId): IntegrationIncident
    {
        $dispatch = null;

        $incident = DB::transaction(function () use (
            $owner,
            $incidentId,
            &$dispatch,
        ): IntegrationIncident {
            $incident = IntegrationIncident::query()
                ->whereBelongsTo($owner, 'owner')
                ->lockForUpdate()
                ->findOrFail($incidentId);

            if ($incident->parked_at === null
                || $incident->recovered_at !== null
                || $incident->failure_kind !== IntegrationFailureKind::Transient) {
                throw new InvalidArgumentException(
                    'Only parked transient work may be replayed.',
                );
            }

            $dispatch = $this->prepareOriginalWork($owner, $incident);

            $incident->forceFill([
                'attempt_count' => 0,
                'first_failed_at' => now(),
                'last_failed_at' => now(),
                'visible_at' => now()->addMinutes(15),
                'retry_until' => now()->addDay(),
                'next_attempt_at' => null,
                'parked_at' => null,
                'acknowledged_at' => null,
                'replay_count' => $incident->replay_count + 1,
                'last_replayed_at' => now(),
            ])->save();

            return $incident;
        }, 3);

        $dispatch?->__invoke();

        return $incident;
    }

    private function prepareOriginalWork(
        User $owner,
        IntegrationIncident $incident,
    ): Closure {
        return match ($incident->work_type) {
            IntegrationWorkType::GmailSynchronization => $this->prepareGmailSynchronization(
                $owner,
                $incident,
            ),
            IntegrationWorkType::GmailMessage => $this->prepareGmailMessage($owner, $incident),
            IntegrationWorkType::DailyExchangeRateSeed => $this->prepareDailyExchangeRateSeed(
                $owner,
                $incident,
            ),
            IntegrationWorkType::ReminderDelivery => $this->prepareReminderDelivery($owner, $incident),
        };
    }

    private function prepareGmailSynchronization(
        User $owner,
        IntegrationIncident $incident,
    ): Closure {
        $connectionId = Str::before($incident->work_id, ':');
        $synchronizationTypeValue = Str::after($incident->work_id, ':');
        $synchronizationType = $synchronizationTypeValue !== $incident->work_id
            ? GmailSynchronizationType::tryFrom($synchronizationTypeValue)
            : null;
        $connection = GmailConnection::query()
            ->whereBelongsTo($owner, 'owner')
            ->findOrFail($connectionId);

        if ($synchronizationType === null || $connection->ingestionIsPaused()) {
            throw new InvalidArgumentException('The original Gmail synchronization is not replayable.');
        }

        return fn () => SynchronizeGmail::dispatch($connection->id, $synchronizationType);
    }

    private function prepareGmailMessage(
        User $owner,
        IntegrationIncident $incident,
    ): Closure {
        $discovery = GmailMessageDiscovery::query()
            ->whereHas('gmailConnection', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->findOrFail($incident->work_id);

        return fn () => ProcessGmailMessage::dispatch($discovery->id);
    }

    private function prepareDailyExchangeRateSeed(
        User $owner,
        IntegrationIncident $incident,
    ): Closure {
        $seedRequest = DailyExchangeRateSeedRequest::query()
            ->whereBelongsTo($owner, 'owner')
            ->lockForUpdate()
            ->findOrFail($incident->work_id);

        if ($seedRequest->retrieval_failed_at === null) {
            throw new InvalidArgumentException('The original BCRP work is not parked.');
        }

        $seedRequest->forceFill([
            'attempt_count' => 0,
            'missing_observation_count' => 0,
            'transport_failure_count' => 0,
            'next_attempt_at' => null,
            'queued_at' => now(),
            'claimed_at' => null,
            'last_attempted_at' => null,
            'retrieval_failed_at' => null,
            'last_error_code' => null,
        ])->save();

        return fn () => SeedDailyExchangeRate::dispatch($seedRequest->id);
    }

    private function prepareReminderDelivery(
        User $owner,
        IntegrationIncident $incident,
    ): Closure {
        $delivery = ReminderDelivery::query()
            ->whereHas('reminder', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->lockForUpdate()
            ->findOrFail($incident->work_id);

        if ($delivery->accepted_at !== null) {
            throw new InvalidArgumentException('The original OpenClaw delivery was already accepted.');
        }

        $delivery->forceFill([
            'attempt_count' => 0,
            'next_attempt_at' => null,
            'queued_at' => now(),
            'claimed_at' => null,
            'last_attempted_at' => null,
            'terminal_at' => null,
            'terminal_reason' => null,
            'last_error_code' => null,
        ])->save();

        return fn () => DeliverReminder::dispatch($delivery->id);
    }
}
