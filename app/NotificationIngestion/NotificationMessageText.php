<?php

namespace App\NotificationIngestion;

use App\Currency;
use App\CurrencyAmount;
use App\Integrations\Gmail\GmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NotificationMessageText
{
    public function visibleBody(GmailMessage $message): ?string
    {
        $body = $message->htmlBody ?? $message->textBody;

        if (! is_string($body)) {
            return null;
        }

        if ($message->htmlBody !== null) {
            $body = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/isu', ' ', $body) ?? '';
            $body = preg_replace('/<[^>]+>/', ' ', $body) ?? '';
        }

        return Str::of(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replace(["\u{200B}", "\u{00A0}", '*'], ' ')
            ->squish()
            ->toString();
    }

    public function trusts(GmailMessage $message, string $senderAddress): bool
    {
        if (! hash_equals($senderAddress, Str::lower($message->fromAddress))) {
            return false;
        }

        $dmarc = $message->authentication['dmarc'];
        $senderDomain = Str::afterLast($senderAddress, '@');

        return $dmarc['result'] === 'pass'
            && ($dmarc['domain'] === null
                || hash_equals($senderDomain, Str::lower($dmarc['domain'])));
    }

    /** @return array{int, Currency} */
    public function money(string $token, string $amount): array
    {
        $currency = match (Str::lower(Str::squish($token))) {
            's/', 's/.' => Currency::Pen,
            '$', 'us$', 'usd' => Currency::Usd,
            default => throw new InvalidArgumentException('Unsupported notification currency.'),
        };
        $amount = Str::squish($amount);
        $hasComma = Str::contains($amount, ',');
        $hasDot = Str::contains($amount, '.');

        if ($hasComma && $hasDot) {
            $decimalSeparator = Str::length(Str::afterLast($amount, ','))
                < Str::length(Str::afterLast($amount, '.'))
                    ? ','
                    : '.';
        } elseif ($hasComma) {
            $decimalSeparator = Str::length(Str::afterLast($amount, ',')) === 2 ? ',' : null;
        } elseif ($hasDot) {
            $decimalSeparator = Str::length(Str::afterLast($amount, '.')) === 2 ? '.' : null;
        } else {
            $decimalSeparator = null;
        }

        if ($decimalSeparator === ',') {
            $amount = Str::replace('.', '', $amount);
            $amount = Str::replace(',', '.', $amount);
        } elseif ($decimalSeparator === '.') {
            $amount = Str::replace(',', '', $amount);
        } else {
            $amount = Str::replace([',', '.'], '', $amount);
        }

        return [CurrencyAmount::minorUnits($amount, $currency), $currency];
    }

    public function date(string $value): CarbonImmutable
    {
        $value = Str::of($value)->lower()->squish()->toString();

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $value, $matches) === 1) {
            return CarbonImmutable::createFromFormat('!d/m/Y', "{$matches[1]}/{$matches[2]}/{$matches[3]}", config('app.timezone'));
        }

        if (preg_match('/^(\d{1,2})(?:\s+de)?\s+([a-záéíóúñ.]+)(?:\s+de)?\s+(\d{4})/u', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Unsupported notification date.');
        }

        $month = match (Str::substr($matches[2], 0, 3)) {
            'ene' => 1,
            'feb' => 2,
            'mar' => 3,
            'abr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'ago' => 8,
            'sep', 'set' => 9,
            'oct' => 10,
            'nov' => 11,
            'dic' => 12,
            default => throw new InvalidArgumentException('Unsupported notification month.'),
        };

        return CarbonImmutable::create(
            (int) $matches[3],
            $month,
            (int) $matches[1],
            timezone: config('app.timezone'),
        )->startOfDay();
    }

    public function lastFour(string $value): ?string
    {
        return preg_match('/(\d{4})(?!.*\d)/', $value, $matches) === 1
            ? $matches[1]
            : null;
    }
}
