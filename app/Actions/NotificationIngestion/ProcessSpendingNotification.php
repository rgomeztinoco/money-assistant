<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Ledger\RecordManualTransaction;
use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use App\SpendingNotificationExtraction;
use App\SpendingNotificationParser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProcessSpendingNotification
{
    public function __construct(
        private SpendingNotificationParser $parser,
        private RecordManualTransaction $recordTransaction,
    ) {}

    public function handle(
        User $owner,
        GmailMessageDiscovery $discovery,
        GmailMessage $message,
    ): ?SpendingNotificationReference {
        return DB::transaction(function () use (
            $owner,
            $discovery,
            $message,
        ): ?SpendingNotificationReference {
            $discovery = GmailMessageDiscovery::query()
                ->with('gmailConnection')
                ->lockForUpdate()
                ->findOrFail($discovery->id);
            $accountIdentity = $discovery->gmailConnection->gmail_account_identity;
            $existingReference = SpendingNotificationReference::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('gmail_account_identity', $accountIdentity)
                ->where('message_id', $message->messageId)
                ->first();

            if ($existingReference !== null) {
                return $existingReference;
            }

            $matches = $this->matchingExtractions($owner, $message);

            if ($matches === []) {
                return null;
            }

            if (count($matches) !== 1) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: 'failed',
                );
            }

            $match = $matches[0];
            $extraction = $match['extraction'];
            $transaction = $this->recordTransaction->handle(
                owner: $owner,
                occurredOn: $extraction->occurredOn,
                amountMinor: $extraction->amountMinor,
                currency: $extraction->currency,
                kind: $extraction->kind,
                merchantDescription: $extraction->merchantDescription,
                provisionalFields: $extraction->provisionalFields,
            );

            return $this->recordOutcome(
                owner: $owner,
                discovery: $discovery,
                accountIdentity: $accountIdentity,
                messageId: $message->messageId,
                outcome: $extraction->provisionalFields === []
                    ? 'created'
                    : 'created_with_review',
                format: $match['format'],
                transactionId: $transaction->id,
            );
        }, 3);
    }

    /**
     * @return list<array{
     *     format: SpendingNotificationFormat,
     *     extraction: SpendingNotificationExtraction
     * }>
     */
    private function matchingExtractions(
        User $owner,
        GmailMessage $message,
    ): array {
        $profiles = ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNotNull('enabled_at')
            ->with('versions.formats')
            ->get();
        $matches = [];

        foreach ($profiles as $profile) {
            $version = $profile->versions->firstWhere(
                'version',
                $profile->current_version,
            );

            if ($version === null) {
                continue;
            }

            foreach ($version->formats as $format) {
                try {
                    $extraction = $this->parser->extract(
                        $message,
                        $version,
                        $format,
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }

                if ($extraction !== null) {
                    $matches[] = [
                        'format' => $format,
                        'extraction' => $extraction,
                    ];
                }
            }
        }

        return $matches;
    }

    private function recordOutcome(
        User $owner,
        GmailMessageDiscovery $discovery,
        string $accountIdentity,
        string $messageId,
        string $outcome,
        ?SpendingNotificationFormat $format = null,
        ?int $transactionId = null,
    ): SpendingNotificationReference {
        $reference = SpendingNotificationReference::create([
            'user_id' => $owner->getKey(),
            'transaction_id' => $transactionId,
            'gmail_account_identity' => $accountIdentity,
            'message_id' => $messageId,
            'processing_outcome' => $outcome,
            'spending_notification_format_id' => $format?->id,
        ]);
        $discovery->forceFill(['processed_at' => now()])->save();

        return $reference;
    }
}
