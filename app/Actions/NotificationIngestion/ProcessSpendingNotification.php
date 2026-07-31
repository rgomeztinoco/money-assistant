<?php

namespace App\Actions\NotificationIngestion;

use App\Actions\Ledger\RecordManualTransaction;
use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
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
        private SynchronizeParserProfileAlerts $synchronizeParserProfileAlerts,
    ) {}

    public function handle(
        User $owner,
        GmailMessageDiscovery $discovery,
        GmailMessage $message,
        bool $retryUnsupported = false,
    ): ?SpendingNotificationReference {
        $discovery->loadMissing('gmailConnection');
        $previousProfileId = SpendingNotificationReference::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('gmail_account_identity', $discovery->gmailConnection->gmail_account_identity)
            ->where('message_id', $message->messageId)
            ->with('profileVersion:id,parser_profile_id')
            ->first()
            ?->profileVersion
            ?->parser_profile_id;
        $reference = DB::transaction(function () use (
            $owner,
            $discovery,
            $message,
            $retryUnsupported,
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

            if ($existingReference !== null && ! $retryUnsupported) {
                return $existingReference;
            }

            if ($existingReference !== null && ! $existingReference->isRetryable()) {
                throw new InvalidArgumentException(
                    'Only an unresolved unsupported message may be retried.',
                );
            }

            $versions = $this->currentProfileVersions($owner);
            $senderVersions = array_values(array_filter(
                $versions,
                fn (ParserProfileVersion $version): bool => $this->parser
                    ->senderMatches($message, $version),
            ));

            if ($senderVersions === []) {
                return null;
            }

            $trustedVersions = array_values(array_filter(
                $senderVersions,
                fn (ParserProfileVersion $version): bool => $this->parser
                    ->trustMatches($message, $version),
            ));

            if ($trustedVersions === []) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::AuthenticationFailed,
                    profileVersion: $senderVersions[0],
                );
            }

            $matches = $this->matchingFormats($trustedVersions, $message);

            if ($matches === []) {
                return $this->recordOutcome(
                    owner: $owner,
                    discovery: $discovery,
                    accountIdentity: $accountIdentity,
                    messageId: $message->messageId,
                    outcome: SpendingNotificationProcessingOutcome::Unsupported,
                    profileVersion: $trustedVersions[0],
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
                    profileVersion: $matches[0]['version'],
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
                    profileVersion: $match['version'],
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
                    profileVersion: $match['version'],
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
                profileVersion: $match['version'],
                transactionId: $transaction->id,
            );
        }, 3);
        $profileIds = array_filter([
            $previousProfileId,
            $reference?->profileVersion()->value('parser_profile_id'),
        ], is_int(...));

        foreach (array_unique($profileIds) as $profileId) {
            $this->synchronizeParserProfileAlerts->handle($owner, $profileId);
        }

        return $reference;
    }

    /**
     * @param  list<ParserProfileVersion>  $versions
     * @return list<array{
     *     version: ParserProfileVersion,
     *     format: SpendingNotificationFormat,
     *     extraction: SpendingNotificationExtraction|null
     * }>
     */
    private function matchingFormats(
        array $versions,
        GmailMessage $message,
    ): array {
        $matches = [];

        foreach ($versions as $version) {
            foreach ($version->formats as $format) {
                if (! $this->parser->formatMatches($message, $format)) {
                    continue;
                }

                if ($format->purpose->isIgnored()) {
                    $extraction = null;
                } else {
                    try {
                        $extraction = $this->parser->extract(
                            $message,
                            $version,
                            $format,
                        );
                    } catch (InvalidArgumentException) {
                        $extraction = null;
                    }
                }

                $matches[] = [
                    'version' => $version,
                    'format' => $format,
                    'extraction' => $extraction,
                ];
            }
        }

        return $matches;
    }

    /** @return list<ParserProfileVersion> */
    private function currentProfileVersions(User $owner): array
    {
        return array_values(ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNotNull('enabled_at')
            ->with('versions.formats')
            ->oldest('id')
            ->get()
            ->map(fn (ParserProfile $profile): ?ParserProfileVersion => $profile
                ->versions
                ->firstWhere('version', $profile->current_version))
            ->filter()
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
        ?ParserProfileVersion $profileVersion = null,
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
            'parser_profile_version_id' => $profileVersion?->id,
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
