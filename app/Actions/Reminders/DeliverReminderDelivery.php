<?php

namespace App\Actions\Reminders;

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\Contracts\OpenClawHook;
use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\ReminderDelivery;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverReminderDelivery
{
    public function __construct(
        private OpenClawHook $openClawHook,
        private ClassifyIntegrationFailure $classifyIntegrationFailure,
        private RecordIntegrationFailure $recordIntegrationFailure,
        private RecordIntegrationRecovery $recordIntegrationRecovery,
    ) {}

    public function handle(string $deliveryId): void
    {
        $delivery = DB::transaction(function () use ($deliveryId): ?ReminderDelivery {
            $delivery = ReminderDelivery::query()->lockForUpdate()->find($deliveryId);

            if ($delivery === null
                || $delivery->accepted_at !== null
                || $delivery->terminal_at !== null
                || ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture())
                || ($delivery->claimed_at !== null && $delivery->claimed_at->isAfter(now()->subMinute()))) {
                return null;
            }

            if ($delivery->reminder->resolved_at !== null
                || $delivery->reminder->dismissed_at !== null
                || ! $delivery->scheduled_for->equalTo($delivery->reminder->scheduled_for)) {
                $delivery->forceFill([
                    'queued_at' => null,
                    'claimed_at' => null,
                    'next_attempt_at' => null,
                    'terminal_at' => now(),
                    'terminal_reason' => 'deterministic_failure',
                    'last_error_code' => 'reminder_inactive',
                ])->save();

                return null;
            }

            $delivery->forceFill([
                'attempt_count' => $delivery->attempt_count + 1,
                'queued_at' => null,
                'claimed_at' => now(),
                'last_attempted_at' => now(),
            ])->save();

            return $delivery;
        });

        if ($delivery === null) {
            return;
        }

        try {
            $this->openClawHook->dispatch(
                eventId: $delivery->id,
                eventType: $delivery->event_type,
                occurredAt: $delivery->occurred_at,
            );
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, $exception);

            return;
        }

        ReminderDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('accepted_at')
            ->whereNull('terminal_at')
            ->where('claimed_at', $delivery->claimed_at)
            ->update([
                'accepted_at' => now(),
                'queued_at' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

        $this->recordIntegrationRecovery->handle(
            owner: $delivery->reminder->owner,
            integration: IntegrationService::OpenClaw,
            workType: IntegrationWorkType::ReminderDelivery,
            workId: $delivery->id,
        );
    }

    private function recordFailure(ReminderDelivery $delivery, Throwable $exception): void
    {
        $status = $exception instanceof RequestException
            ? $exception->response->status()
            : null;
        $failureKind = $this->classifyIntegrationFailure->handle($exception);
        $decision = $this->recordIntegrationFailure->handle(
            owner: $delivery->reminder->owner,
            integration: IntegrationService::OpenClaw,
            workType: IntegrationWorkType::ReminderDelivery,
            workId: $delivery->id,
            sourceIdentity: 'openclaw:'.$delivery->event_type.':'.$delivery->id,
            failureKind: $failureKind,
            errorCode: $status === null ? 'connection_failed' : "http_{$status}",
        );

        ReminderDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('accepted_at')
            ->whereNull('terminal_at')
            ->where('claimed_at', $delivery->claimed_at)
            ->update([
                'queued_at' => null,
                'claimed_at' => null,
                'next_attempt_at' => $decision->nextAttemptAt,
                'terminal_at' => $decision->shouldRetry ? null : now(),
                'terminal_reason' => $decision->shouldRetry
                    ? null
                    : $this->terminalReason($failureKind),
                'last_error_code' => $status === null ? 'connection_failed' : "http_{$status}",
                'updated_at' => now(),
            ]);
    }

    private function terminalReason(IntegrationFailureKind $failureKind): string
    {
        if ($failureKind->isTransient()) {
            return 'retry_exhausted';
        }

        if ($failureKind === IntegrationFailureKind::Authentication
            || $failureKind === IntegrationFailureKind::Authorization) {
            return 'authorization_rejected';
        }

        if ($failureKind === IntegrationFailureKind::Schema
            || $failureKind === IntegrationFailureKind::Validation) {
            return 'validation_rejected';
        }

        return 'deterministic_failure';
    }
}
