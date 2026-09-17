<?php

namespace App\NotificationIngestion\Formats;

use App\Contracts\NotificationIngestion\SpendingNotificationFormatAdapter;
use App\Integrations\Gmail\GmailMessage;
use App\MovementDirection;
use App\NotificationIngestion\NotificationMessageText;
use App\NotificationIngestion\SupportedSpendingNotification;
use App\SpendingNotificationExtraction;
use App\TransactionKind;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class YapeSpendingNotificationAdapter implements SpendingNotificationFormatAdapter
{
    private const SENDER = 'notificaciones@yape.pe';

    private const SUBJECT = 'Por tu seguridad, te notificaremos por cada yapeo que realices';

    public function __construct(private NotificationMessageText $messageText) {}

    public function fixtureFiles(): array
    {
        return [
            'yape.outgoing_spending' => 'yape-outgoing-spending.json',
        ];
    }

    public function match(GmailMessage $message): ?SupportedSpendingNotification
    {
        if ($message->subject !== self::SUBJECT
            || ! $this->messageText->trusts($message, self::SENDER)) {
            return null;
        }

        $body = $this->messageText->visibleBody($message);

        if ($body === null) {
            return null;
        }

        if (preg_match(
            '/Monto de yapeo\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+).*?Fecha y Hora de la operación\s+(.+?)\s+-\s+\d{1,2}:\d{2}\s+[ap]\.\s*m\..*?Nombre del Beneficiario\s+(.+?)\s+N[º°] de operación/iu',
            $body,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException('The Yape notification does not match its supported fixture.');
        }

        [$amountMinor, $currency] = $this->messageText->money($matches[1], $matches[2]);

        return new SupportedSpendingNotification(
            formatIdentifier: 'yape.outgoing_spending',
            extraction: new SpendingNotificationExtraction(
                occurredOn: $this->messageText->date($matches[3]),
                amountMinor: $amountMinor,
                currency: $currency,
                kind: TransactionKind::Spending,
                description: Str::limit('Yape to '.Str::squish($matches[4]), 255, ''),
                provisionalFields: [],
                direction: MovementDirection::Debit,
                instrumentLabel: 'Yape',
            ),
        );
    }
}
