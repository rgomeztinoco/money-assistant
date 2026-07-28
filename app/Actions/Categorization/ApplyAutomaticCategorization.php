<?php

namespace App\Actions\Categorization;

use App\Models\Transaction;

class ApplyAutomaticCategorization
{
    public function __construct(
        private ApplyLearnedRuleToTransaction $applyLearnedRuleToTransaction,
        private ScheduleAiClassification $scheduleAiClassification,
    ) {}

    public function handle(Transaction $transaction): Transaction
    {
        $transaction = $this->applyLearnedRuleToTransaction->handle($transaction);
        $this->scheduleAiClassification->handle($transaction);

        return $transaction;
    }
}
