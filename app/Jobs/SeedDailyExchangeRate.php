<?php

namespace App\Jobs;

use App\Actions\Reporting\SeedDailyExchangeRateFromBcrpData;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SeedDailyExchangeRate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public function __construct(public int $seedRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->seedRequestId;
    }

    public function handle(SeedDailyExchangeRateFromBcrpData $seedDailyExchangeRate): void
    {
        $seedDailyExchangeRate->handle($this->seedRequestId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A Daily Exchange Rate seed job failed.', [
            'seed_request_id' => $this->seedRequestId,
            'exception' => $exception,
        ]);
    }
}
