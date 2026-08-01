<?php

namespace App\Jobs;

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\Actions\NotificationIngestion\SynchronizeGmailConnection;
use App\GmailSynchronizationType;
use App\IntegrationService;
use App\IntegrationWorkType;
use App\Models\GmailConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynchronizeGmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 60;

    public int $uniqueFor = 90000;

    public function __construct(
        public int $connectionId,
        public GmailSynchronizationType $type,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->connectionId}:{$this->type->value}";
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

    public function handle(
        SynchronizeGmailConnection $synchronize,
        ClassifyIntegrationFailure $classifyIntegrationFailure,
        RecordIntegrationFailure $recordIntegrationFailure,
        RecordIntegrationRecovery $recordIntegrationRecovery,
    ): void {
        $connection = GmailConnection::query()->findOrFail($this->connectionId);
        $workId = $this->uniqueId();

        try {
            $synchronize->handle($this->connectionId, $this->type);
        } catch (Throwable $exception) {
            $decision = $recordIntegrationFailure->handle(
                owner: $connection->owner,
                integration: IntegrationService::Gmail,
                workType: IntegrationWorkType::GmailSynchronization,
                workId: $workId,
                sourceIdentity: 'gmail:'.$connection->gmail_account_identity
                    .':synchronization:'.$this->type->value,
                failureKind: $classifyIntegrationFailure->handle($exception),
                errorCode: 'gmail_synchronization_failed',
            );

            if ($decision->shouldRetry) {
                $this->release($decision->nextAttemptAt);
            } else {
                $this->fail($exception);
            }

            return;
        }

        $recordIntegrationRecovery->handle(
            owner: $connection->owner,
            integration: IntegrationService::Gmail,
            workType: IntegrationWorkType::GmailSynchronization,
            workId: $workId,
        );
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
