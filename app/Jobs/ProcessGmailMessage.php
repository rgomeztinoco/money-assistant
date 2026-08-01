<?php

namespace App\Jobs;

use App\Actions\Integrations\ClassifyIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationFailure;
use App\Actions\Integrations\RecordIntegrationRecovery;
use App\Actions\NotificationIngestion\ProcessDiscoveredGmailMessage;
use App\IntegrationService;
use App\IntegrationWorkType;
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

    public int $tries = 0;

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

    public function handle(
        ProcessDiscoveredGmailMessage $processDiscoveredGmailMessage,
        ClassifyIntegrationFailure $classifyIntegrationFailure,
        RecordIntegrationFailure $recordIntegrationFailure,
        RecordIntegrationRecovery $recordIntegrationRecovery,
    ): void {
        $discovery = GmailMessageDiscovery::query()
            ->with('gmailConnection.owner')
            ->findOrFail($this->discoveryId);

        try {
            $processDiscoveredGmailMessage->handle($this->discoveryId);
        } catch (Throwable $exception) {
            $decision = $recordIntegrationFailure->handle(
                owner: $discovery->gmailConnection->owner,
                integration: IntegrationService::Gmail,
                workType: IntegrationWorkType::GmailMessage,
                workId: (string) $discovery->id,
                sourceIdentity: 'gmail:'.$discovery->gmailConnection->gmail_account_identity
                    .':message:'.$discovery->message_id,
                failureKind: $classifyIntegrationFailure->handle($exception),
                errorCode: 'gmail_message_processing_failed',
            );

            if ($decision->shouldRetry) {
                $this->release($decision->nextAttemptAt);
            } else {
                $this->fail($exception);
            }

            return;
        }

        $recordIntegrationRecovery->handle(
            owner: $discovery->gmailConnection->owner,
            integration: IntegrationService::Gmail,
            workType: IntegrationWorkType::GmailMessage,
            workId: (string) $discovery->id,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('A Gmail message processing job failed.', [
            'gmail_message_discovery_id' => $this->discoveryId,
            'exception' => $exception,
        ]);
    }
}
