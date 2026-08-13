<?php

namespace App\Actions\NotificationIngestion;

use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use App\Models\User;
use App\SpendingNotificationFormatPurpose;
use App\SpendingNotificationParser;
use App\ValidatedSpendingNotificationFormat;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class ValidateSpendingNotificationFormat
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
    public function handle(
        User $owner,
        array $attributes,
        ?ParserProfile $profile = null,
    ): ValidatedSpendingNotificationFormat {
        $discovery = GmailMessageDiscovery::query()
            ->with('gmailConnection')
            ->whereKey($attributes['source_message_discovery_id'])
            ->sole();
        $message = $this->readSourceMessage->sourceMessage($owner, $discovery);
        $profile ??= $this->profileFromMessage($owner, $attributes, $message);

        if (! $this->parser->trustMatches($message, $profile)) {
            throw new InvalidArgumentException(
                'The selected Gmail message does not satisfy this profile sender authentication.',
            );
        }

        $definition = $this->definition($attributes);
        $format = new SpendingNotificationFormat([
            'name' => $attributes['format_name'],
            'mime_source' => $attributes['mime_source'],
            'purpose' => $attributes['format_purpose'],
            'definition' => $definition,
            'rule_identifier' => $this->ruleIdentifier(
                $attributes['mime_source'],
                $definition,
            ),
        ]);
        $extraction = $format->purpose->isIgnored()
            ? null
            : $this->parser->extract($message, $profile, $format);

        if (! $this->parser->formatMatches($message, $format)
            || (! $format->purpose->isIgnored() && $extraction === null)) {
            throw new InvalidArgumentException(
                'The format does not match and extract from the selected Gmail message.',
            );
        }

        return new ValidatedSpendingNotificationFormat(
            profile: $profile,
            format: $format,
            discovery: $discovery,
            message: $message,
            extraction: $extraction,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function profileFromMessage(User $owner, array $attributes, mixed $message): ParserProfile
    {
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

        return new ParserProfile([
            'user_id' => $owner->getKey(),
            'name' => $attributes['profile_name'],
            'trusted_sender_address' => $fromAddress,
            'trusted_sender_domain' => $fromDomain,
            'authentication_mechanism' => $authenticationMechanism,
            'authenticated_domain' => Str::lower($authentication['domain']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function definition(array $attributes): array
    {
        if ($attributes['format_purpose'] === SpendingNotificationFormatPurpose::Ignore->value) {
            return [
                'subject_marker' => $attributes['subject_marker'],
                'body_marker' => $attributes['body_marker'],
            ];
        }

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
        return str_replace(['\\r', '\\n', '\\t'], ["\r", "\n", "\t"], $boundary);
    }

    /** @param array<string, mixed> $definition */
    private function ruleIdentifier(string $mimeSource, array $definition): string
    {
        return hash('sha256', json_encode([
            'mime_source' => $mimeSource,
            'definition' => $definition,
        ], JSON_THROW_ON_ERROR));
    }
}
