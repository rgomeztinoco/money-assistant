<?php

namespace App\Jobs;

use App\Actions\NotificationIngestion\SynchronizeGmailConnection;
use App\GmailSynchronizationType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynchronizeGmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct(
        public int $connectionId,
        public GmailSynchronizationType $type,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->connectionId}:{$this->type->value}";
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("gmail-connection:{$this->connectionId}"))
                ->shared()
                ->releaseAfter(60)
                ->expireAfter(75),
        ];
    }

    public function handle(SynchronizeGmailConnection $synchronize): void
    {
        $synchronize->handle($this->connectionId, $this->type);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A Gmail synchronization job failed.', [
            'gmail_connection_id' => $this->connectionId,
            'synchronization_type' => $this->type->value,
            'exception' => $exception,
        ]);
    }
}
