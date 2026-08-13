<?php

namespace App\Jobs;

use App\Actions\NotificationIngestion\ProcessDiscoveredGmailMessage;
use App\Models\GmailMessageDiscovery;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGmailMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 90000;

    public function __construct(public int $discoveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->discoveryId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new RateLimited('gmail-message-processing'))->releaseAfter(60),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProcessDiscoveredGmailMessage $processDiscoveredGmailMessage): void
    {
        $processDiscoveredGmailMessage->handle($this->discoveryId);
    }

    public function failed(?Throwable $exception): void
    {
        $failedJobUuid = $this->job?->uuid();

        GmailMessageDiscovery::query()
            ->whereKey($this->discoveryId)
            ->whereNull('processed_at')
            ->update([
                'processing_failed_at' => now(),
                'last_error_code' => 'gmail_message_processing_failed',
                'failed_job_uuid' => $failedJobUuid,
            ]);

        Log::error('A Gmail message processing job failed.', [
            'gmail_message_discovery_id' => $this->discoveryId,
            'exception' => $exception,
        ]);
    }
}
