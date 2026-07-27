<?php

namespace App\Actions\Reminders;

use App\Contracts\OpenClawHook;
use App\Models\ReminderDelivery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DeliverReminderDelivery
{
    public function __construct(private OpenClawHook $openClawHook) {}

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
    }

    private function recordFailure(ReminderDelivery $delivery, Throwable $exception): void
    {
        $status = $exception instanceof RequestException
            ? $exception->response->status()
            : null;
        $isTransient = $exception instanceof ConnectionException
            || $status === Response::HTTP_TOO_MANY_REQUESTS
            || ($status !== null && $status >= Response::HTTP_INTERNAL_SERVER_ERROR);
        $shouldRetry = $isTransient && $delivery->attempt_count < 3;

        ReminderDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('accepted_at')
            ->whereNull('terminal_at')
            ->where('claimed_at', $delivery->claimed_at)
            ->update([
                'queued_at' => null,
                'claimed_at' => null,
                'next_attempt_at' => $shouldRetry
                    ? now()->addSeconds($this->retryDelayInSeconds($delivery))
                    : null,
                'terminal_at' => $shouldRetry ? null : now(),
                'terminal_reason' => $shouldRetry
                    ? null
                    : $this->terminalReason($status, $isTransient),
                'last_error_code' => $status === null ? 'connection_failed' : "http_{$status}",
                'updated_at' => now(),
            ]);
    }

    private function retryDelayInSeconds(ReminderDelivery $delivery): int
    {
        $baseDelay = $delivery->attempt_count === 1 ? 60 : 120;
        $jitter = hexdec(substr(hash('sha256', $delivery->id), 0, 2)) % 31;

        return $baseDelay + $jitter;
    }

    private function terminalReason(?int $status, bool $isTransient): string
    {
        if ($isTransient) {
            return 'retry_exhausted';
        }

        if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
            return 'authorization_rejected';
        }

        if ($status !== null && $status >= 400 && $status < 500) {
            return 'validation_rejected';
        }

        return 'deterministic_failure';
    }
}
