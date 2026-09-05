<?php

namespace App\NotificationIngestion\Formats;

use App\Contracts\NotificationIngestion\SpendingNotificationFormatAdapter;
use App\Currency;
use App\Integrations\Gmail\GmailMessage;
use App\MovementDirection;
use App\NotificationIngestion\NotificationMessageText;
use App\NotificationIngestion\SupportedSpendingNotification;
use App\SpendingNotificationExtraction;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InterbankSpendingNotificationAdapter implements SpendingNotificationFormatAdapter
{
    private const SENDER = 'servicioalcliente@netinterbank.com.pe';

    public function __construct(private NotificationMessageText $messageText) {}

    public function fixtureFiles(): array
    {
        return [
            'interbank.card_spending' => 'interbank-card-spending.json',
            'interbank.plin_card_spending' => 'interbank-plin-card-spending.json',
        ];
    }

    public function match(GmailMessage $message): ?SupportedSpendingNotification
    {
        if (! $this->messageText->trusts($message, self::SENDER)) {
            return null;
        }

        $body = $this->messageText->visibleBody($message);

        if ($body === null) {
            return null;
        }

        if ($message->subject === 'Constancia de Pago Plin TC') {
            return $this->plinCardSpending($body);
        }

        if (Str::contains($message->subject, [
            'realizaste un consumo con tu Tarjeta',
            'se ha realizado un pago recurrente a tu Tarjeta',
        ])) {
            return $this->cardSpending($body);
        }

        return null;
    }

    private function cardSpending(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Tarjeta:\s*([^\s]+)\s*Comercio:\s*(.+?)\s*Monto:\s*(S\/?\.?|US\$|USD|\$)\s*([\d.,]+)\s*Fecha:\s*(\d{2}\/\d{2}\/\d{4})/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[3], $matches[4]);

        return $this->result(
            identifier: 'interbank.card_spending',
            occurredOn: $this->messageText->date($matches[5]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Spending,
            direction: MovementDirection::Debit,
            description: Str::squish($matches[2]),
            instrumentLabel: 'Interbank card',
            instrumentLastFour: $this->messageText->lastFour($matches[1]),
        );
    }

    private function plinCardSpending(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Constancia de Pago Plin\s+(\d{1,2}\s+[A-Za-záéíóúñ.]+\s+\d{4}).*?Tarjeta cargo:\s*(.+?)\s+Detalle de pago:.*?Destinatario:\s*(.+?)\s+Destino:\s*.+?\s+Moneda y monto:\s*(S\/?\.?|US\$|USD|\$)\s*([\d.,]+)/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[4], $matches[5]);

        return $this->result(
            identifier: 'interbank.plin_card_spending',
            occurredOn: $this->messageText->date($matches[1]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Spending,
            direction: MovementDirection::Debit,
            description: Str::limit('PLIN to '.Str::squish($matches[3]), 255, ''),
            instrumentLabel: 'Interbank card',
            instrumentLastFour: $this->messageText->lastFour($matches[2]),
        );
    }

    private function result(
        string $identifier,
        CarbonImmutable $occurredOn,
        int $amountMinor,
        Currency $currency,
        TransactionKind $kind,
        MovementDirection $direction,
        string $description,
        string $instrumentLabel,
        ?string $instrumentLastFour,
    ): SupportedSpendingNotification {
        return new SupportedSpendingNotification(
            formatIdentifier: $identifier,
            extraction: new SpendingNotificationExtraction(
                occurredOn: $occurredOn,
                amountMinor: $amountMinor,
                currency: $currency,
                kind: $kind,
                description: $description,
                provisionalFields: [],
                direction: $direction,
                instrumentLabel: $instrumentLabel,
                instrumentLastFour: $instrumentLastFour,
            ),
        );
    }

    /** @return array<int, string> */
    private function capture(string $pattern, string $body): array
    {
        if (preg_match($pattern, $body, $matches) !== 1) {
            throw new InvalidArgumentException('The Interbank notification does not match its supported fixture.');
        }

        return $matches;
    }
}
