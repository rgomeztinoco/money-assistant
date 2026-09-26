<?php

namespace App\Actions\NotificationIngestion;

use App\Jobs\ProcessGmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

final class RetryGmailReviewMessage
{
    public function __construct(private RetryFailedGmailMessage $retryFailedGmailMessage) {}

    public function handle(User $owner, int $discoveryId): void
    {
        $discovery = GmailMessageDiscovery::query()
            ->with(['gmailConnection', 'reference'])
            ->whereHas('gmailConnection', fn ($query) => $query->whereBelongsTo($owner, 'owner'))
            ->find($discoveryId);

        if ($discovery === null) {
            throw (new ModelNotFoundException)->setModel(GmailMessageDiscovery::class, [$discoveryId]);
        }

        if ($discovery->gmailConnection->ingestionIsPaused()
            || $discovery->dismissed_at !== null
            || $discovery->reference?->transaction_id !== null) {
            throw new InvalidArgumentException('This Gmail message is no longer eligible for retry.');
        }

        if ($discovery->processing_failed_at !== null) {
            $this->retryFailedGmailMessage->handle($owner, $discoveryId);

            return;
        }

        if ($discovery->reference?->isRetryable() !== true) {
            throw new InvalidArgumentException('This Gmail message is no longer eligible for retry.');
        }

        ProcessGmailMessage::dispatch($discoveryId, retryUnsupported: true);
    }
}
