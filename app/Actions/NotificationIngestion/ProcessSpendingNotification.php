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
use App\SpendingNotificationProcessingOutcome;
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
        bool $retryUnsupported = false,
    ): SpendingNotificationReference {
        return DB::transaction(function () use (
            $owner,
            $discovery,
            $message,
            $retryUnsupported,
        ): SpendingNotificationReference {
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

            if ($existingReference !== null && ! $retryUnsupported) {
                return $existingReference;
            }

            if ($existingReference !== null && ! $existingReference->isRetryable()) {
                throw new InvalidArgumentException(
                    'Only an unresolved unsupported message may be retried.',
                );
            }

            $profiles = $this->enabledProfiles($owner);
            $senderProfiles = array_values(array_filter(
                $profiles,
                fn (ParserProfile $profile): bool => $this->parser
                    ->senderMatches($message, $profile),
            ));

            if ($senderProfiles === []) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Unsupported,
                );
            }

            $trustedProfiles = array_values(array_filter(
                $senderProfiles,
                fn (ParserProfile $profile): bool => $this->parser
                    ->trustMatches($message, $profile),
            ));

            if ($trustedProfiles === []) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::AuthenticationFailed,
                );
            }

            $matches = $this->matchingFormats($trustedProfiles, $message);

            if ($matches === []) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Unsupported,
                );
            }

            if (count($matches) !== 1) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Failed,
                    format: $matches[0]['format'],
                );
            }

            $match = $matches[0];

            if ($match['format']->purpose->isIgnored()) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Ignored,
                    format: $match['format'],
                );
            }

            if ($match['extraction'] === null) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Failed,
                    format: $match['format'],
                );
            }

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
                    ? SpendingNotificationProcessingOutcome::Created
                    : SpendingNotificationProcessingOutcome::CreatedWithReview,
                format: $match['format'],
                transactionId: $transaction->id,
            );
        }, 3);
    }

    /**
     * @param  list<ParserProfile>  $profiles
     * @return list<array{
     *     profile: ParserProfile,
     *     format: SpendingNotificationFormat,
     *     extraction: SpendingNotificationExtraction|null
     * }>
     */
    private function matchingFormats(
        array $profiles,
        GmailMessage $message,
    ): array {
        $matches = [];

        foreach ($profiles as $profile) {
            foreach ($profile->formats as $format) {
                if (! $this->parser->formatMatches($message, $format)) {
                    continue;
                }

                if ($format->purpose->isIgnored()) {
                    $extraction = null;
                } else {
                    try {
                        $extraction = $this->parser->extract(
                            $message,
                            $profile,
                            $format,
                        );
                    } catch (InvalidArgumentException) {
                        $extraction = null;
                    }
                }

                $matches[] = [
                    'profile' => $profile,
                    'format' => $format,
                    'extraction' => $extraction,
                ];
            }
        }

        return $matches;
    }

    /** @return list<ParserProfile> */
    private function enabledProfiles(User $owner): array
    {
        return array_values(ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNotNull('enabled_at')
            ->with(['formats' => fn ($query) => $query
                ->whereNotNull('enabled_at')
                ->oldest('id')])
            ->oldest('id')
            ->get()
            ->values()
            ->all());
    }

    private function recordOutcome(
        User $owner,
        GmailMessageDiscovery $discovery,
        string $accountIdentity,
        string $messageId,
        SpendingNotificationProcessingOutcome $outcome,
        ?SpendingNotificationFormat $format = null,
        ?int $transactionId = null,
    ): SpendingNotificationReference {
        $reference = SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('gmail_account_identity', $accountIdentity)
            ->where('message_id', $messageId)
            ->first();
        $attemptCount = $reference === null
            ? 1
            : $reference->attempt_count + 1;
        $attributes = [
            'user_id' => $owner->getKey(),
            'transaction_id' => $transactionId,
            'gmail_account_identity' => $accountIdentity,
            'message_id' => $messageId,
            'processing_outcome' => $outcome->value,
            'spending_notification_format_id' => $format?->id,
            'gmail_message_discovery_id' => $discovery->id,
            'attempt_count' => $attemptCount,
            'last_attempted_at' => now(),
        ];

        if ($reference === null) {
            $reference = SpendingNotificationReference::create($attributes);
        } else {
            $reference->forceFill($attributes)->save();
        }
        $discovery->forceFill(['processed_at' => now()])->save();

        return $reference;
    }
}
