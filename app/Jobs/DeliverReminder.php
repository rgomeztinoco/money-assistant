<?php

namespace App\Jobs;

use App\Actions\Reminders\DeliverReminderDelivery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public string $deliveryId) {}

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(DeliverReminderDelivery $deliverReminder): void
    {
        $deliverReminder->handle($this->deliveryId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A Reminder delivery job failed.', [
            'delivery_id' => $this->deliveryId,
            'exception' => $exception,
        ]);
    }
}
