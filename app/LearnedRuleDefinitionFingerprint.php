<?php

namespace App;

final class LearnedRuleDefinitionFingerprint
{
    public function make(
        int $categoryId,
        string $merchantKey,
        LearnedRuleMatchMode $matchMode,
        ?TransactionKind $transactionKind,
        ?Currency $currency,
        ?string $paymentInstrumentLabel,
        ?string $paymentInstrumentLastFour,
    ): string {
        return hash('sha256', implode("\x1F", [
            (string) $categoryId,
            $merchantKey,
            $matchMode->value,
            $transactionKind === null ? '' : $transactionKind->value,
            $currency === null ? '' : $currency->value,
            $paymentInstrumentLabel ?? '',
            $paymentInstrumentLastFour ?? '',
        ]));
    }
}
