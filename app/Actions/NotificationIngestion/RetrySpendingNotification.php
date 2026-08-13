<?php

namespace App\Actions\NotificationIngestion;

use App\Models\SpendingNotificationReference;
use InvalidArgumentException;

final class RetrySpendingNotification
{
    public function __construct(
        private ReadParserProfileSourceMessage $readSourceMessage,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    public function handle(
        SpendingNotificationReference $reference,
    ): SpendingNotificationReference {
        $reference = SpendingNotificationReference::query()
            ->with('discovery.gmailConnection')
            ->findOrFail($reference->id);

        if ($reference->discovery === null || ! $reference->isRetryable()) {
            throw new InvalidArgumentException(
                'Only an unresolved unsupported message may be retried.',
            );
        }

        $message = $this->readSourceMessage->sourceMessage($reference->discovery);

        return $this->processSpendingNotification->handle(
            discovery: $reference->discovery,
            message: $message,
            retryUnsupported: true,
        );
    }
}
