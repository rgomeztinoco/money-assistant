<?php

namespace App\Actions\NotificationIngestion;

use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationFormat;
use App\Models\User;
use App\ParserProfileProposal;
use App\SpendingNotificationParser;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class BuildParserProfileProposal
{
    public function __construct(
        private ReadParserProfileSourceMessage $readSourceMessage,
        private SpendingNotificationParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public function handle(User $owner, array $attributes): ParserProfileProposal
    {
        $parserProfileId = $attributes['parser_profile_id'] ?? null;
        $existingProfile = null;

        if ($parserProfileId !== null && ! is_int($parserProfileId)) {
            throw new InvalidArgumentException('The Parser Profile identifier is invalid.');
        }

        if (is_int($parserProfileId)) {
            $existingProfile = ParserProfile::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($parserProfileId)
                ->sole();
        } elseif (ParserProfile::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereRaw('lower(name) = lower(?)', [$attributes['profile_name']])
            ->exists()) {
            throw new InvalidArgumentException('A Parser Profile with this name already exists.');
        }

        $discoveryId = $attributes['source_message_discovery_id'] ?? null;

        if (! is_int($discoveryId)) {
            throw new InvalidArgumentException('The source Gmail message identifier is invalid.');
        }

        $discovery = GmailMessageDiscovery::query()
            ->whereKey($discoveryId)
            ->sole();
        $message = $this->readSourceMessage->sourceMessage($owner, $discovery);
        $fromAddress = Str::lower($message->fromAddress);
        $fromDomain = Str::lower(Str::afterLast($fromAddress, '@'));
        $authenticationMechanism = $attributes['authentication_mechanism'];
        $authentication = $message->authentication[$authenticationMechanism] ?? null;

        if (! is_array($authentication)
            || ($authentication['result'] ?? null) !== 'pass'
            || ! is_string($authentication['domain'] ?? null)
            || ! hash_equals($fromDomain, Str::lower($authentication['domain']))) {
            throw new InvalidArgumentException(
                'The selected Gmail authentication result is not aligned with the sender.',
            );
        }

        $definition = $this->definition($attributes);
        $profileVersion = new ParserProfileVersion([
            'version' => $existingProfile instanceof ParserProfile
                ? $existingProfile->current_version + 1
                : 1,
            'trusted_sender_address' => $fromAddress,
            'trusted_sender_domain' => $fromDomain,
            'authentication_mechanism' => $authenticationMechanism,
            'authenticated_domain' => Str::lower($authentication['domain']),
            'source_gmail_account_identity' => $discovery->gmailConnection->gmail_account_identity,
            'source_message_id' => $message->messageId,
            'approved_at' => now(),
        ]);
        $format = new SpendingNotificationFormat([
            'name' => $attributes['format_name'],
            'mime_source' => $attributes['mime_source'],
            'definition' => $definition,
            'rule_identifier' => $this->ruleIdentifier(
                $attributes['mime_source'],
                $definition,
            ),
        ]);
        $extraction = $this->parser->extract($message, $profileVersion, $format);

        if ($extraction === null) {
            throw new InvalidArgumentException(
                'The exact sender and format markers do not match the selected message.',
            );
        }

        return new ParserProfileProposal(
            existingProfile: $existingProfile,
            profileName: (string) ($attributes['profile_name'] ?? $existingProfile?->name),
            discovery: $discovery,
            message: $message,
            profileVersion: $profileVersion,
            format: $format,
            extraction: $extraction,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function definition(array $attributes): array
    {
        $groupingSeparator = match ($attributes['grouping_separator']) {
            'none' => null,
            'space' => ' ',
            default => $attributes['grouping_separator'],
        };
        $merchantRule = null;

        if (is_string($attributes['merchant_prefix'] ?? null)
            && is_string($attributes['merchant_suffix'] ?? null)) {
            $merchantRule = [
                'prefix' => $this->decodeBoundary($attributes['merchant_prefix']),
                'suffix' => $this->decodeBoundary($attributes['merchant_suffix']),
            ];
        }

        return [
            'subject_marker' => $attributes['subject_marker'],
            'body_marker' => $attributes['body_marker'],
            'amount' => [
                'prefix' => $this->decodeBoundary($attributes['amount_prefix']),
                'suffix' => $this->decodeBoundary($attributes['amount_suffix']),
                'decimal_separator' => $attributes['decimal_separator'],
                'grouping_separator' => $groupingSeparator,
                'currency_position' => $attributes['currency_position'],
                'currency_mapping' => [
                    $attributes['currency_token'] => $attributes['currency'],
                ],
                'semantics' => $attributes['amount_semantics'],
            ],
            'date' => [
                'prefix' => $this->decodeBoundary($attributes['date_prefix']),
                'suffix' => $this->decodeBoundary($attributes['date_suffix']),
                'format' => $attributes['date_format'],
                'timezone' => $attributes['timezone'],
            ],
            'merchant' => $merchantRule,
            'kind' => ['semantics' => $attributes['kind_semantics']],
        ];
    }

    private function decodeBoundary(string $boundary): string
    {
        return str_replace(
            ['\\r', '\\n', '\\t'],
            ["\r", "\n", "\t"],
            $boundary,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws JsonException
     */
    private function ruleIdentifier(string $mimeSource, array $definition): string
    {
        return hash('sha256', json_encode([
            'mime_source' => $mimeSource,
            'definition' => $definition,
        ], JSON_THROW_ON_ERROR));
    }
}
