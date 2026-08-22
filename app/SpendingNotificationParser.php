<?php

namespace App;

use App\Integrations\Gmail\GmailMessage;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class SpendingNotificationParser
{
    /**
     * Return null when trust or exact format markers do not match. A matched
     * format with invalid gating fields fails closed.
     */
    public function extract(
        GmailMessage $message,
        ParserProfile $profile,
        SpendingNotificationFormat $format,
    ): ?SpendingNotificationExtraction {
        if (! $this->trustMatches($message, $profile)) {
            return null;
        }

        if (! $this->formatMatches($message, $format)) {
            return null;
        }

        $definition = $format->definition;
        $body = $format->mime_source === 'text_plain'
            ? $message->textBody
            : $message->htmlBody;

        if (! is_string($body)) {
            return null;
        }

        $kind = $this->kind($definition['kind'] ?? null);
        [$amountMinor, $currency] = $this->amount(
            body: $body,
            rule: $definition['amount'] ?? null,
            kind: $kind,
        );
        [$occurredOn, $occurrenceDateNeedsReview] = $this->occurrenceDate(
            body: $body,
            rule: $definition['date'] ?? null,
            receivedAt: $message->receivedAt,
        );
        [$merchantDescription, $merchantNeedsReview] = $this->merchant(
            body: $body,
            rule: $definition['merchant'] ?? null,
        );
        $provisionalFields = [];

        if ($occurrenceDateNeedsReview) {
            $provisionalFields[] = ReviewableTransactionField::OccurredOn;
        }

        if ($merchantNeedsReview) {
            $provisionalFields[] = ReviewableTransactionField::MerchantDescription;
        }

        return new SpendingNotificationExtraction(
            occurredOn: $occurredOn,
            amountMinor: $amountMinor,
            currency: $currency,
            kind: $kind,
            merchantDescription: $merchantDescription,
            provisionalFields: $provisionalFields,
        );
    }

    public function senderMatches(
        GmailMessage $message,
        ParserProfile $profile,
    ): bool {
        return hash_equals(
            $profile->trusted_sender_address,
            Str::lower($message->fromAddress),
        ) && hash_equals(
            $profile->trusted_sender_domain,
            Str::lower(Str::afterLast($message->fromAddress, '@')),
        );
    }

    public function formatMatches(
        GmailMessage $message,
        SpendingNotificationFormat $format,
    ): bool {
        $definition = $format->definition;
        $body = match ($format->mime_source) {
            'text_plain' => $message->textBody,
            'text_html' => $message->htmlBody,
            default => null,
        };

        return is_string($body)
            && is_string($definition['subject_marker'] ?? null)
            && is_string($definition['body_marker'] ?? null)
            && Str::contains($message->subject, $definition['subject_marker'])
            && Str::contains($body, $definition['body_marker']);
    }

    public function trustMatches(
        GmailMessage $message,
        ParserProfile $profile,
    ): bool {
        if (! $this->senderMatches($message, $profile)) {
            return false;
        }

        $authentication = $message->authentication[$profile->authentication_mechanism]
            ?? null;

        return is_array($authentication)
            && ($authentication['result'] ?? null) === 'pass'
            && is_string($authentication['domain'] ?? null)
            && hash_equals(
                $profile->authenticated_domain,
                Str::lower($authentication['domain']),
            );
    }

    private function kind(mixed $rule): TransactionKind
    {
        $semantics = is_array($rule) ? ($rule['semantics'] ?? null) : null;

        return match ($semantics) {
            'fixed_purchase' => TransactionKind::Spending,
            'fixed_refund' => TransactionKind::Refund,
            default => throw new InvalidArgumentException(
                'The Transaction-kind semantics are not supported.',
            ),
        };
    }

    /**
     * @return array{int, Currency}
     */
    private function amount(
        string $body,
        mixed $rule,
        TransactionKind $kind,
    ): array {
        if (! is_array($rule)
            || ! is_string($rule['prefix'] ?? null)
            || ! is_string($rule['suffix'] ?? null)
            || ! is_string($rule['decimal_separator'] ?? null)
            || ! array_key_exists('grouping_separator', $rule)
            || ! is_string($rule['currency_position'] ?? null)
            || ! is_array($rule['currency_mapping'] ?? null)
            || ! is_string($rule['semantics'] ?? null)) {
            throw new InvalidArgumentException('The amount grammar is incomplete.');
        }

        $values = $this->valuesBetween($body, $rule['prefix'], $rule['suffix']);

        if (count($values) !== 1) {
            throw new InvalidArgumentException(
                'The format must yield exactly one amount.',
            );
        }

        $candidate = Str::squish($values[0]);
        $matches = [];

        foreach ($rule['currency_mapping'] as $token => $currencyValue) {
            if (! is_string($token)
                || $token === ''
                || ! is_string($currencyValue)
                || Currency::tryFrom($currencyValue) === null) {
                continue;
            }

            $numericValue = match ($rule['currency_position']) {
                'before' => Str::startsWith($candidate, $token)
                    ? trim(Str::after($candidate, $token))
                    : null,
                'after' => Str::endsWith($candidate, $token)
                    ? trim(Str::beforeLast($candidate, $token))
                    : null,
                default => null,
            };

            if (is_string($numericValue) && $numericValue !== '') {
                $matches[] = [
                    $this->amountMinor(
                        $numericValue,
                        $rule['decimal_separator'],
                        $rule['grouping_separator'],
                        $rule['semantics'],
                        $kind,
                    ),
                    Currency::from($currencyValue),
                ];
            }
        }

        if (count($matches) !== 1) {
            throw new InvalidArgumentException(
                'The format must yield exactly one supported currency.',
            );
        }

        return $matches[0];
    }

    private function amountMinor(
        string $value,
        string $decimalSeparator,
        mixed $groupingSeparator,
        string $semantics,
        TransactionKind $kind,
    ): int {
        $sign = '';

        if (Str::startsWith($value, ['+', '-'])) {
            $sign = Str::substr($value, 0, 1);
            $value = Str::substr($value, 1);
        }

        if (($semantics === 'absolute' && $sign !== '')
            || ($semantics === 'signed' && $sign === '')
            || ! in_array($semantics, ['absolute', 'signed'], true)
            || ($semantics === 'signed'
                && (($kind === TransactionKind::Spending && $sign !== '+')
                    || ($kind === TransactionKind::Refund && $sign !== '-')))) {
            throw new InvalidArgumentException(
                'The amount sign does not agree with the declared semantics.',
            );
        }

        if (! in_array($decimalSeparator, ['.', ','], true)
            || ! in_array($groupingSeparator, [null, '.', ',', ' '], true)
            || $groupingSeparator === $decimalSeparator) {
            throw new InvalidArgumentException('The amount separators are invalid.');
        }

        $escapedDecimal = preg_quote($decimalSeparator, '/');
        $integerPattern = '\\d+';

        if (is_string($groupingSeparator)) {
            $escapedGrouping = preg_quote($groupingSeparator, '/');
            $integerPattern = "(?:\\d+|\\d{1,3}(?:{$escapedGrouping}\\d{3})+)";
        }

        if (preg_match(
            "/^({$integerPattern})(?:{$escapedDecimal}(\\d{2}))?$/D",
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('The amount does not match its grammar.');
        }

        $whole = is_string($groupingSeparator)
            ? str_replace($groupingSeparator, '', $matches[1])
            : $matches[1];
        $minor = ExactInteger::from($whole)
            ->multiply(ExactInteger::from(100))
            ->add(ExactInteger::from($matches[2] ?? '0'));

        if ($minor->compare(ExactInteger::from(0)) !== 1
            || $minor->compare(ExactInteger::from(PHP_INT_MAX)) === 1) {
            throw new InvalidArgumentException('The amount is outside the supported range.');
        }

        return (int) $minor->value();
    }

    /**
     * @return array{CarbonImmutable, bool}
     */
    private function occurrenceDate(
        string $body,
        mixed $rule,
        CarbonImmutable $receivedAt,
    ): array {
        $timezone = is_array($rule) && is_string($rule['timezone'] ?? null)
            ? $rule['timezone']
            : (string) config('app.timezone');
        $fallback = $receivedAt->setTimezone($timezone)->startOfDay();

        if (! is_array($rule)
            || ! is_string($rule['prefix'] ?? null)
            || ! is_string($rule['suffix'] ?? null)
            || ! is_string($rule['format'] ?? null)) {
            return [$fallback, true];
        }

        $values = $this->valuesBetween($body, $rule['prefix'], $rule['suffix']);

        if (count($values) !== 1) {
            return [$fallback, true];
        }

        $value = trim($values[0]);

        try {
            $occurredOn = CarbonImmutable::createFromFormat(
                '!'.$rule['format'],
                $value,
                $timezone,
            );
        } catch (Throwable) {
            return [$fallback, true];
        }

        if ($occurredOn->format($rule['format']) !== $value
            || $occurredOn->lessThan($fallback->subYear())
            || $occurredOn->greaterThan($fallback->addDay())) {
            return [$fallback, true];
        }

        return [$occurredOn->startOfDay(), false];
    }

    /**
     * @return array{string, bool}
     */
    private function merchant(string $body, mixed $rule): array
    {
        if (! is_array($rule)
            || ! is_string($rule['prefix'] ?? null)
            || ! is_string($rule['suffix'] ?? null)) {
            return ['Unknown merchant', true];
        }

        $values = $this->valuesBetween($body, $rule['prefix'], $rule['suffix']);

        if (count($values) !== 1) {
            return ['Unknown merchant', true];
        }

        $merchant = Str::squish(html_entity_decode(
            strip_tags($values[0]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
        $genericMerchants = [
            'merchant',
            'purchase',
            'card purchase',
            'transaction',
        ];

        if ($merchant === ''
            || Str::length($merchant) > 255
            || Str::endsWith($merchant, ['...', '…'])
            || in_array(Str::lower($merchant), $genericMerchants, true)) {
            return ['Unknown merchant', true];
        }

        return [$merchant, false];
    }

    /**
     * @return list<string>
     */
    private function valuesBetween(
        string $source,
        string $prefix,
        string $suffix,
    ): array {
        if ($prefix === '' || $suffix === '') {
            return [];
        }

        $values = [];
        $offset = 0;

        while (($prefixPosition = mb_strpos($source, $prefix, $offset)) !== false) {
            $valueStart = $prefixPosition + mb_strlen($prefix);
            $suffixPosition = mb_strpos($source, $suffix, $valueStart);

            if ($suffixPosition === false) {
                break;
            }

            $values[] = mb_substr(
                $source,
                $valueStart,
                $suffixPosition - $valueStart,
            );
            $offset = $suffixPosition + mb_strlen($suffix);
        }

        return $values;
    }
}
