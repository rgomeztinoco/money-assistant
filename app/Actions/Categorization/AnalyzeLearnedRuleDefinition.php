<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\MerchantNormalizer;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleRevision;
use App\Models\Transaction;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AnalyzeLearnedRuleDefinition
{
    public function __construct(
        private MerchantNormalizer $merchantNormalizer,
        private ResolveLearnedRuleForTransaction $resolveLearnedRuleForTransaction,
    ) {}

    /**
     * @return array{
     *     definition: array{category_id: int, category_name: string, merchant_pattern: string, merchant_key: string, match_mode: string, transaction_kind: string|null, currency: string|null, payment_instrument_label: string|null, payment_instrument_last_four: string|null},
     *     existing_match_count: int,
     *     existing_matches: list<array{id: int, revision: int, merchant_description: string, category_name: string|null}>,
     *     new_match_count: int,
     *     new_matches: list<array{id: int, revision: int, merchant_description: string, category_name: string|null}>,
     *     lost_match_count: int,
     *     lost_matches: list<array{id: int, revision: int, merchant_description: string, category_name: string|null}>,
     *     overlaps: list<array{rule_id: int, revision: int, category_id: int, category_name: string, merchant_pattern: string, precedence: string}>,
     *     future_behavior: array{wins_over: int, ties: int, loses_to: int},
     *     blocked: bool,
     *     resource_fingerprint: string
     * }
     */
    public function handle(
        User $owner,
        Category $category,
        string $merchantPattern,
        LearnedRuleMatchMode $matchMode,
        ?TransactionKind $transactionKind,
        ?Currency $currency,
        ?string $paymentInstrumentLabel,
        ?string $paymentInstrumentLastFour,
        ?LearnedRule $revisedRule = null,
    ): array {
        $merchantPattern = Str::squish($merchantPattern);
        $merchantKey = $this->merchantNormalizer->normalize($merchantPattern);

        if ($merchantKey === '') {
            throw ValidationException::withMessages([
                'merchant_pattern' => 'Enter a merchant pattern with searchable text.',
            ]);
        }

        $definition = [
            'category_id' => $category->id,
            'category_name' => $category->name,
            'merchant_pattern' => $merchantPattern,
            'merchant_key' => $merchantKey,
            'match_mode' => $matchMode->value,
            'transaction_kind' => $transactionKind?->value,
            'currency' => $currency?->value,
            'payment_instrument_label' => $paymentInstrumentLabel === null ? null : Str::squish($paymentInstrumentLabel),
            'payment_instrument_last_four' => $paymentInstrumentLastFour,
        ];
        $proposedRevision = new LearnedRuleRevision($definition);
        $proposedRank = $this->resolveLearnedRuleForTransaction->specificity($proposedRevision);
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->with('category:id,name')
            ->orderBy('id')
            ->get();
        $existingMatches = $transactions
            ->filter(fn (Transaction $transaction): bool => $this->resolveLearnedRuleForTransaction->rank($proposedRevision, $transaction) !== null)
            ->map(fn (Transaction $transaction): array => $this->transactionData($transaction))
            ->values()
            ->all();
        $existingMatches = array_values($existingMatches);
        $currentMatches = $revisedRule?->currentRevision === null
            ? []
            : array_values($transactions
                ->filter(fn (Transaction $transaction): bool => $this->resolveLearnedRuleForTransaction->rank($revisedRule->currentRevision, $transaction) !== null)
                ->map(fn (Transaction $transaction): array => $this->transactionData($transaction))
                ->values()
                ->all());
        $existingMatchIds = collect($existingMatches)->pluck('id');
        $currentMatchIds = collect($currentMatches)->pluck('id');
        $newMatches = array_values(collect($existingMatches)->whereIn('id', $existingMatchIds->diff($currentMatchIds))->all());
        $lostMatches = array_values(collect($currentMatches)->whereIn('id', $currentMatchIds->diff($existingMatchIds))->all());

        $overlaps = LearnedRule::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('retired_at')
            ->when($revisedRule !== null, fn ($query) => $query->whereKeyNot($revisedRule->id))
            ->with('currentRevision.category:id,name')
            ->orderBy('id')
            ->get()
            ->filter(fn (LearnedRule $rule): bool => $rule->currentRevision !== null
                && $this->definitionsOverlap($proposedRevision, $rule->currentRevision))
            ->map(function (LearnedRule $rule) use ($proposedRevision, $proposedRank): array {
                $revision = $rule->currentRevision;
                $existingRank = $this->resolveLearnedRuleForTransaction->specificity($revision);
                $precedence = $this->resolveLearnedRuleForTransaction->compareSpecificity($proposedRank, $existingRank);

                if ($precedence === 'equal') {
                    $precedence = $proposedRevision->category_id === $revision->category_id
                        ? 'equal_same_target'
                        : 'equal_conflict';
                }

                return [
                    'rule_id' => $rule->id,
                    'revision' => $revision->revision,
                    'category_id' => $revision->category_id,
                    'category_name' => $revision->category->name,
                    'merchant_pattern' => $revision->merchant_pattern,
                    'precedence' => $precedence,
                ];
            })
            ->values()
            ->all();
        $overlaps = array_values($overlaps);
        $futureBehavior = [
            'wins_over' => collect($overlaps)->where('precedence', 'proposed_wins')->count(),
            'ties' => collect($overlaps)->whereIn('precedence', ['equal_same_target', 'equal_conflict'])->count(),
            'loses_to' => collect($overlaps)->where('precedence', 'existing_wins')->count(),
        ];
        $blocked = collect($overlaps)->contains('precedence', 'equal_conflict');
        $fingerprintPayload = [
            'definition' => $definition,
            'category_revision' => $category->revision,
            'existing_matches' => $existingMatches,
            'new_matches' => $newMatches,
            'lost_matches' => $lostMatches,
            'overlaps' => $overlaps,
            'revised_rule' => $revisedRule === null ? null : [$revisedRule->id, $revisedRule->revision, $revisedRule->retired_at?->toIso8601String()],
        ];

        return [
            'definition' => $definition,
            'existing_match_count' => count($existingMatches),
            'existing_matches' => $existingMatches,
            'new_match_count' => count($newMatches),
            'new_matches' => $newMatches,
            'lost_match_count' => count($lostMatches),
            'lost_matches' => $lostMatches,
            'overlaps' => $overlaps,
            'future_behavior' => $futureBehavior,
            'blocked' => $blocked,
            'resource_fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)),
        ];
    }

    private function definitionsOverlap(LearnedRuleRevision $left, LearnedRuleRevision $right): bool
    {
        foreach (['transaction_kind', 'currency', 'payment_instrument_last_four'] as $scope) {
            if ($left->{$scope} !== null && $right->{$scope} !== null && $left->{$scope} !== $right->{$scope}) {
                return false;
            }
        }

        if ($left->payment_instrument_label !== null
            && $right->payment_instrument_label !== null
            && $this->merchantNormalizer->normalize($left->payment_instrument_label)
                !== $this->merchantNormalizer->normalize($right->payment_instrument_label)) {
            return false;
        }

        if ($left->match_mode === LearnedRuleMatchMode::Exact) {
            return $this->keyMatches($left->merchant_key, $right);
        }

        if ($right->match_mode === LearnedRuleMatchMode::Exact) {
            return $this->keyMatches($right->merchant_key, $left);
        }

        if ($left->match_mode === LearnedRuleMatchMode::StartsWith
            && $right->match_mode === LearnedRuleMatchMode::StartsWith) {
            return Str::startsWith($left->merchant_key, $right->merchant_key)
                || Str::startsWith($right->merchant_key, $left->merchant_key);
        }

        return true;
    }

    private function keyMatches(string $key, LearnedRuleRevision $definition): bool
    {
        return match ($definition->match_mode) {
            LearnedRuleMatchMode::Exact => $key === $definition->merchant_key,
            LearnedRuleMatchMode::StartsWith => Str::startsWith($key, $definition->merchant_key),
            LearnedRuleMatchMode::Contains => Str::contains($key, $definition->merchant_key),
        };
    }

    /** @return array{id: int, revision: int, merchant_description: string, category_name: string|null} */
    private function transactionData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'revision' => $transaction->revision,
            'merchant_description' => $transaction->merchant_description,
            'category_name' => $transaction->category?->name,
        ];
    }
}
