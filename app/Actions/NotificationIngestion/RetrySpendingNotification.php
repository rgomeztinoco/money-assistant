<?php

namespace App\Actions\NotificationIngestion;

use App\Models\SpendingNotificationReference;
use App\Models\User;
use InvalidArgumentException;

final class RetrySpendingNotification
{
    public function __construct(
        private ReadParserProfileSourceMessage $readSourceMessage,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    public function handle(
        User $owner,
        SpendingNotificationReference $reference,
    ): SpendingNotificationReference {
        $reference = SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->with('discovery.gmailConnection')
            ->findOrFail($reference->id);

        if ($reference->discovery === null || ! $reference->isRetryable()) {
            throw new InvalidArgumentException(
                'Only an unresolved unsupported message may be retried.',
            );
        }

        $message = $this->readSourceMessage->sourceMessage(
            $owner,
            $reference->discovery,
        );

        return $this->processSpendingNotification->handle(
            owner: $owner,
            discovery: $reference->discovery,
            message: $message,
            retryUnsupported: true,
        ) ?? throw new InvalidArgumentException(
            'The message no longer matches an enabled Parser Profile.',
        );
    }
}
