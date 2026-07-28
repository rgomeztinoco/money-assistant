<?php

namespace App\Jobs;

use App\Actions\Categorization\ClassifyTransactionWithAi;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyTransaction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $classificationRequestId) {}

    public function uniqueId(): string
    {
        return (string) $this->classificationRequestId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new RateLimited('ai-classifier'))->dontRelease()];
    }

    public function handle(ClassifyTransactionWithAi $classifyTransaction): void
    {
        $classifyTransaction->handle($this->classificationRequestId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('An AI Transaction classification job failed.', [
            'classification_request_id' => $this->classificationRequestId,
            'exception' => $exception,
        ]);
    }
}
