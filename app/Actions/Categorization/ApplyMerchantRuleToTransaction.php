<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ApplyMerchantRuleToTransaction
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    public function handle(Transaction $transaction): Transaction
    {
        return DB::transaction(function () use ($transaction): Transaction {
            $lockedTransaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->sole();

            if ($lockedTransaction->category_id !== null
                || $lockedTransaction->category_assignment_provenance !== null) {
                return $lockedTransaction;
            }

            $matchingRules = MerchantRule::query()
                ->where('merchant_key', $this->merchantNormalizer->normalize($lockedTransaction->merchant_description))
                ->where('enabled', true)
                ->whereHas('category', fn (Builder $query) => $query->whereNull('archived_at'))
                ->where(fn (Builder $query) => $query
                    ->whereNull('transaction_kind')
                    ->orWhere('transaction_kind', $lockedTransaction->kind))
                ->where(fn (Builder $query) => $query
                    ->whereNull('currency')
                    ->orWhere('currency', $lockedTransaction->currency))
                ->limit(2)
                ->get();

            if ($matchingRules->count() !== 1) {
                return $lockedTransaction;
            }

            $matchingRule = $matchingRules->sole();
            $lockedTransaction->category_id = $matchingRule->category_id;
            $lockedTransaction->category_assignment_provenance = CategoryAssignmentProvenance::MerchantRule;
            $lockedTransaction->merchant_rule_id = $matchingRule->id;
            $lockedTransaction->save();

            return $lockedTransaction;
        }, 3);
    }
}
