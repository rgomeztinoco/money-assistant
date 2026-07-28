<?php

namespace App\Actions\Categorization;

use App\Models\AiClassificationRequest;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ScheduleAiClassification
{
    public function __construct(
        private ResolveLearnedRuleForTransaction $resolveLearnedRuleForTransaction,
    ) {}

    public function handle(Transaction $transaction): ?AiClassificationRequest
    {
        return DB::transaction(function () use ($transaction): ?AiClassificationRequest {
            $lockedTransaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->sole();

            if ($lockedTransaction->category_id !== null
                || $lockedTransaction->category_assignment_provenance !== null) {
                return null;
            }

            $learnedRuleResolution = $this->resolveLearnedRuleForTransaction->handle(
                $lockedTransaction,
            );

            if ($learnedRuleResolution['winner'] !== null || $learnedRuleResolution['conflict']) {
                return null;
            }

            AiClassificationRequest::query()->insertOrIgnore([
                'user_id' => $lockedTransaction->user_id,
                'transaction_id' => $lockedTransaction->id,
                'expected_transaction_revision' => $lockedTransaction->revision,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return AiClassificationRequest::query()
                ->where('transaction_id', $lockedTransaction->id)
                ->sole();
        }, 3);
    }
}
