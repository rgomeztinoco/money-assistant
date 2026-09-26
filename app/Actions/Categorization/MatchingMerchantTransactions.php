<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Database\Eloquent\Builder;

final class MatchingMerchantTransactions
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    /**
     * @return array{count: int, transactions: list<array{id: int, occurred_on: string, description: string, amount_minor: string, currency: string, category: string|null}>}
     */
    public function preview(User $owner, string $merchant, ?TransactionKind $kind, ?Currency $currency, ?int $excludeRuleId = null): array
    {
        $count = 0;
        $transactions = [];

        $merchantKey = $this->merchantNormalizer->normalize($merchant);

        foreach ($this->candidates($owner, $kind, $currency, $excludeRuleId)->lazyById(200) as $transaction) {
            if (! $this->matches($transaction, $merchantKey)) {
                continue;
            }

            $count++;

            if (count($transactions) < 20) {
                $transactions[] = [
                    'id' => $transaction->id,
                    'occurred_on' => $transaction->occurred_on->toDateString(),
                    'description' => $transaction->description,
                    'amount_minor' => (string) $transaction->amount_minor,
                    'currency' => $transaction->currency->value,
                    'category' => $transaction->category?->name,
                ];
            }
        }

        return ['count' => $count, 'transactions' => $transactions];
    }

    public function apply(User $owner, MerchantRule $rule): int
    {
        $count = 0;
        $merchantKey = $rule->merchant_key;

        $this->candidates($owner, $rule->transaction_kind, $rule->currency, $rule->id)
            ->chunkById(200, function ($transactions) use ($rule, $merchantKey, &$count): void {
                foreach ($transactions as $transaction) {
                    if (! $this->matches($transaction, $merchantKey)) {
                        continue;
                    }

                    $transaction->category_id = $rule->category_id;
                    $transaction->category_assignment_provenance = CategoryAssignmentProvenance::MerchantRule;
                    $transaction->merchant_rule_id = $rule->id;
                    $transaction->save();
                    $count++;
                }
            });

        return $count;
    }

    /** @return Builder<Transaction> */
    private function candidates(User $owner, ?TransactionKind $kind, ?Currency $currency, ?int $excludeRuleId = null): Builder
    {
        return Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
            ->when($excludeRuleId !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('merchant_rule_id')
                ->orWhere('merchant_rule_id', '!=', $excludeRuleId)))
            ->whereDoesntHave('receiptBreakdown')
            ->with('category:id,name')
            ->orderBy('id');
    }

    private function matches(Transaction $transaction, string $merchantKey): bool
    {
        return $this->merchantNormalizer->normalize($transaction->description)
            === $merchantKey;
    }
}
