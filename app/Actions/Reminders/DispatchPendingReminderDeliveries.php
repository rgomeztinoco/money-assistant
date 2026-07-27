<?php

namespace App\Actions\Reminders;

use App\Jobs\DeliverReminder;
use App\Models\ReminderDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DispatchPendingReminderDeliveries
{
    public function handle(): void
    {
        /** @var Collection<int, string> $deliveryIds */
        $deliveryIds = DB::transaction(function (): Collection {
            $deliveries = ReminderDelivery::query()
                ->whereNull('accepted_at')
                ->whereNull('terminal_at')
                ->where(fn ($query) => $query
                    ->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query
                    ->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', now()->subMinutes(2)))
                ->where(fn ($query) => $query
                    ->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinute()))
                ->orderBy('next_attempt_at')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            $deliveries->each(function (ReminderDelivery $delivery): void {
                $delivery->forceFill(['queued_at' => now()])->save();
            });

            return $deliveries->pluck('id');
        });

        $deliveryIds->each(
            fn (string $deliveryId) => DeliverReminder::dispatch($deliveryId),
        );
    }
}
