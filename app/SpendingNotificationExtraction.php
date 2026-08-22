<?php

namespace App;

use Carbon\CarbonImmutable;

final readonly class SpendingNotificationExtraction
{
    /**
     * @param  list<ReviewableTransactionField>  $provisionalFields
     */
    public function __construct(
        public CarbonImmutable $occurredOn,
        public int $amountMinor,
        public Currency $currency,
        public TransactionKind $kind,
        public string $description,
        public array $provisionalFields,
    ) {}
}
