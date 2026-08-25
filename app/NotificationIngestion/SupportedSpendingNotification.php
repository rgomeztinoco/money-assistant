<?php

namespace App\NotificationIngestion;

use App\SpendingNotificationExtraction;

final readonly class SupportedSpendingNotification
{
    public function __construct(
        public string $formatIdentifier,
        public SpendingNotificationExtraction $extraction,
    ) {}

    /**
     * @return array{
     *     format_identifier: string,
     *     occurred_on: string,
     *     amount_minor: int,
     *     currency: string,
     *     kind: string,
     *     direction: string|null,
     *     income_source: string|null,
     *     transfer_purpose: string|null,
     *     description: string,
     *     instrument_label: string|null,
     *     instrument_last_four: string|null
     * }
     */
    public function fixtureExpectation(): array
    {
        return [
            'format_identifier' => $this->formatIdentifier,
            'occurred_on' => $this->extraction->occurredOn->toDateString(),
            'amount_minor' => $this->extraction->amountMinor,
            'currency' => $this->extraction->currency->value,
            'kind' => $this->extraction->kind->value,
            'direction' => $this->extraction->direction?->value,
            'income_source' => $this->extraction->incomeSource?->value,
            'transfer_purpose' => $this->extraction->transferPurpose?->value,
            'description' => $this->extraction->description,
            'instrument_label' => $this->extraction->instrumentLabel,
            'instrument_last_four' => $this->extraction->instrumentLastFour,
        ];
    }
}
