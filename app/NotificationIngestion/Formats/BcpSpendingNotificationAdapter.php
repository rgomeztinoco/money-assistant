<?php

namespace App\NotificationIngestion\Formats;

use App\Contracts\NotificationIngestion\SpendingNotificationFormatAdapter;
use App\Currency;
use App\IncomeSource;
use App\Integrations\Gmail\GmailMessage;
use App\MovementDirection;
use App\NotificationIngestion\NotificationMessageText;
use App\NotificationIngestion\SupportedSpendingNotification;
use App\SpendingNotificationExtraction;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BcpSpendingNotificationAdapter implements SpendingNotificationFormatAdapter
{
    private const SENDER = 'notificaciones@notificacionesbcp.com.pe';

    public function __construct(private NotificationMessageText $messageText) {}

    public function fixtureFiles(): array
    {
        return [
            'bcp.debit_card_spending' => 'bcp-debit-card-spending.json',
            'bcp.foreign_transfer_income' => 'bcp-foreign-transfer-income.json',
            'bcp.other_bank_transfer_spending' => 'bcp-other-bank-transfer-spending.json',
            'bcp.own_account_transfer' => 'bcp-own-account-transfer.json',
            'bcp.warda_withdrawal' => 'bcp-warda-withdrawal.json',
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

        return match (true) {
            Str::contains($message->subject, 'Realizaste un consumo con tu Tarjeta de Débito BCP') => $this->debitCardSpending($body),
            Str::startsWith($message->subject, 'Transferencia del Exterior') => $this->foreignTransferIncome($body),
            $message->subject === 'Constancia de Transferencia a Otros Bancos - Servicio de Notificaciones BCP' => $this->otherBankTransferSpending($body),
            $message->subject === 'Constancia de Transferencia Entre mis Cuentas - Servicio de Notificaciones BCP' => $this->ownAccountTransfer($body),
            Str::contains(Str::lower($message->subject), 'retiro de tu wardadito') => $this->wardaWithdrawal($body),
            default => null,
        };
    }

    private function debitCardSpending(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Total del consumo\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+).*?Fecha y hora\s+(.+?)\s+-\s+\d{1,2}:\d{2}\s+[AP]M.*?Número de Tarjeta de Débito\s+([^\s]+).*?Empresa\s+(.+?)\s+Número de operación/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[1], $matches[2]);

        return $this->result(
            identifier: 'bcp.debit_card_spending',
            occurredOn: $this->messageText->date($matches[3]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Spending,
            direction: MovementDirection::Debit,
            description: Str::squish($matches[5]),
            instrumentLabel: 'BCP debit card',
            instrumentLastFour: $this->messageText->lastFour($matches[4]),
        );
    }

    private function foreignTransferIncome(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Total abonado\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+).*?Fecha de abono\s+(\d{2}\/\d{2}\/\d{4}).*?Ordenante\s+(.+?)\s+Banco corresponsal.*?Cuenta destino\s+(.+?)\s+Dirección banco destino/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[1], $matches[2]);

        return $this->result(
            identifier: 'bcp.foreign_transfer_income',
            occurredOn: $this->messageText->date($matches[3]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Income,
            direction: MovementDirection::Credit,
            description: Str::limit(Str::squish($matches[4]), 255, ''),
            instrumentLabel: 'BCP account',
            instrumentLastFour: $this->messageText->lastFour($matches[5]),
            incomeSource: IncomeSource::IndependentWork,
        );
    }

    private function otherBankTransferSpending(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Total cobrado\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+).*?Fecha y hora\s+(.+?)\s+-\s+\d{1,2}:\d{2}\s+[AP]M\s+Enviado a\s+(.+?)\s+Banco destino.*?Desde\s+(.+?)\s+Mensaje/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[1], $matches[2]);

        return $this->result(
            identifier: 'bcp.other_bank_transfer_spending',
            occurredOn: $this->messageText->date($matches[3]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Spending,
            direction: MovementDirection::Debit,
            description: Str::limit('Transfer to '.Str::squish($matches[4]), 255, ''),
            instrumentLabel: 'BCP account',
            instrumentLastFour: $this->messageText->lastFour($matches[5]),
        );
    }

    private function ownAccountTransfer(string $body): SupportedSpendingNotification
    {
        $amountMatches = preg_match(
            '/Total cobrado al tipo de cambio\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+)/iu',
            $body,
            $matches,
        ) === 1
            ? $matches
            : $this->capture('/Monto transferido\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+)/iu', $body);
        $detailMatches = $this->capture(
            '/Fecha y hora\s+(.+?)\s+-\s+\d{1,2}:\d{2}\s+[AP]M.*?Desde\s+(.+?)\s+(?:Enviado a|Mensaje)/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($amountMatches[1], $amountMatches[2]);

        return $this->result(
            identifier: 'bcp.own_account_transfer',
            occurredOn: $this->messageText->date($detailMatches[1]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Transfer,
            direction: MovementDirection::Debit,
            description: 'Transfer between own accounts',
            instrumentLabel: 'BCP account',
            instrumentLastFour: $this->messageText->lastFour($detailMatches[2]),
            transferPurpose: TransferPurpose::Internal,
        );
    }

    private function wardaWithdrawal(string $body): SupportedSpendingNotification
    {
        $matches = $this->capture(
            '/Total retirado\s+(S\/?\.?|US\$|USD|\$)\s*([\d.,]+).*?Fecha y hora\s+(.+?)\s+-\s+\d{1,2}:\d{2}(?::\d{2})?.*?Destino\s+(.+?)(?:\s+¿No reconoces|$)/iu',
            $body,
        );
        [$amountMinor, $currency] = $this->messageText->money($matches[1], $matches[2]);

        return $this->result(
            identifier: 'bcp.warda_withdrawal',
            occurredOn: $this->messageText->date($matches[3]),
            amountMinor: $amountMinor,
            currency: $currency,
            kind: TransactionKind::Transfer,
            direction: MovementDirection::Credit,
            description: 'WARDA withdrawal',
            instrumentLabel: 'BCP account',
            instrumentLastFour: $this->messageText->lastFour($matches[4]),
            transferPurpose: TransferPurpose::Savings,
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
        ?IncomeSource $incomeSource = null,
        ?TransferPurpose $transferPurpose = null,
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
                incomeSource: $incomeSource,
                transferPurpose: $transferPurpose,
                instrumentLabel: $instrumentLabel,
                instrumentLastFour: $instrumentLastFour,
            ),
        );
    }

    /** @return array<int, string> */
    private function capture(string $pattern, string $body): array
    {
        if (preg_match($pattern, $body, $matches) !== 1) {
            throw new InvalidArgumentException('The BCP notification does not match its supported fixture.');
        }

        return $matches;
    }
}
