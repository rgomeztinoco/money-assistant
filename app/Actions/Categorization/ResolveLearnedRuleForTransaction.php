<?php

namespace App\Actions\Categorization;

use App\LearnedRuleMatchMode;
use App\MerchantNormalizer;
use App\Models\LearnedRule;
use App\Models\LearnedRuleRevision;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ResolveLearnedRuleForTransaction
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    /**
     * @return array{
     *     matches: list<array{revision: LearnedRuleRevision, constraint_count: int, mode_priority: int, pattern_length: int}>,
     *     winner: LearnedRuleRevision|null,
     *     conflict: bool
     * }
     */
    public function handle(Transaction $transaction): array
    {
        return $this->handleWithRules($transaction, $this->activeRules($transaction->user_id));
    }

    /** @return Collection<int, LearnedRule> */
    public function activeRules(int $ownerId): Collection
    {
        return LearnedRule::query()
            ->where('user_id', $ownerId)
            ->whereNull('retired_at')
            ->whereHas('currentRevision.category', fn ($query) => $query->whereNull('retired_at'))
            ->with('currentRevision')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, LearnedRule> $rules */
    public function fingerprint(Collection $rules): string
    {
        return hash('sha256', json_encode($rules->map(fn (LearnedRule $rule): array => [
            'id' => $rule->id,
            'revision' => $rule->revision,
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Collection<int, LearnedRule>  $rules
     * @return array{
     *     matches: list<array{revision: LearnedRuleRevision, constraint_count: int, mode_priority: int, pattern_length: int}>,
     *     winner: LearnedRuleRevision|null,
     *     conflict: bool
     * }
     */
    public function handleWithRules(Transaction $transaction, Collection $rules): array
    {
        $merchantKey = $this->merchantNormalizer->normalize($transaction->merchant_description);
        $matches = $rules
            ->map(fn (LearnedRule $rule): ?array => $this->rank($rule->currentRevision, $transaction, $merchantKey))
            ->filter()
            ->sort(function (array $left, array $right): int {
                foreach (['constraint_count', 'mode_priority', 'pattern_length'] as $rank) {
                    $comparison = $right[$rank] <=> $left[$rank];

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return $left['revision']->learned_rule_id <=> $right['revision']->learned_rule_id;
            })
            ->values();

        if ($matches->isEmpty()) {
            return ['matches' => [], 'winner' => null, 'conflict' => false];
        }

        $best = $matches->first();
        $equallySpecific = $matches->filter(fn (array $match): bool => $this->sameRank($match, $best));
        $conflict = $equallySpecific->pluck('revision.category_id')->unique()->count() > 1;

        return [
            'matches' => array_values($matches->all()),
            'winner' => $conflict ? null : $best['revision'],
            'conflict' => $conflict,
        ];
    }

    /**
     * @return array{revision: LearnedRuleRevision, constraint_count: int, mode_priority: int, pattern_length: int}|null
     */
    public function rank(
        ?LearnedRuleRevision $revision,
        Transaction $transaction,
        ?string $merchantKey = null,
    ): ?array {
        $merchantKey ??= $this->merchantNormalizer->normalize($transaction->merchant_description);

        if ($revision === null
            || ($revision->transaction_kind !== null && $revision->transaction_kind !== $transaction->kind)
            || ($revision->currency !== null && $revision->currency !== $transaction->currency)
            || ! $this->paymentInstrumentMatches($revision, $transaction)
            || ! $this->merchantMatches($revision, $merchantKey)) {
            return null;
        }

        return ['revision' => $revision, ...$this->specificity($revision)];
    }

    /** @return array{constraint_count: int, mode_priority: int, pattern_length: int} */
    public function specificity(LearnedRuleRevision $revision): array
    {
        return [
            'constraint_count' => collect([
                $revision->transaction_kind,
                $revision->currency,
                $revision->payment_instrument_label,
                $revision->payment_instrument_last_four,
            ])->filter(fn (mixed $constraint): bool => $constraint !== null)->count(),
            'mode_priority' => match ($revision->match_mode) {
                LearnedRuleMatchMode::Exact => 3,
                LearnedRuleMatchMode::StartsWith => 2,
                LearnedRuleMatchMode::Contains => 1,
            },
            'pattern_length' => Str::length($revision->merchant_key),
        ];
    }

    /**
     * @param  array{constraint_count: int, mode_priority: int, pattern_length: int}  $left
     * @param  array{constraint_count: int, mode_priority: int, pattern_length: int}  $right
     */
    public function compareSpecificity(array $left, array $right): string
    {
        foreach (['constraint_count', 'mode_priority', 'pattern_length'] as $rank) {
            if ($left[$rank] > $right[$rank]) {
                return 'proposed_wins';
            }

            if ($left[$rank] < $right[$rank]) {
                return 'existing_wins';
            }
        }

        return 'equal';
    }

    private function merchantMatches(LearnedRuleRevision $revision, string $merchantKey): bool
    {
        return match ($revision->match_mode) {
            LearnedRuleMatchMode::Exact => $merchantKey === $revision->merchant_key,
            LearnedRuleMatchMode::StartsWith => Str::startsWith($merchantKey, $revision->merchant_key),
            LearnedRuleMatchMode::Contains => Str::contains($merchantKey, $revision->merchant_key),
        };
    }

    private function paymentInstrumentMatches(LearnedRuleRevision $revision, Transaction $transaction): bool
    {
        $label = $transaction->getAttribute('payment_instrument_label');
        $lastFour = $transaction->getAttribute('payment_instrument_last_four');

        return ($revision->payment_instrument_label === null
                || (is_string($label)
                    && $this->merchantNormalizer->normalize($label) === $this->merchantNormalizer->normalize($revision->payment_instrument_label)))
            && ($revision->payment_instrument_last_four === null
                || $lastFour === $revision->payment_instrument_last_four);
    }

    /**
     * @param  array{constraint_count: int, mode_priority: int, pattern_length: int}  $left
     * @param  array{constraint_count: int, mode_priority: int, pattern_length: int}  $right
     */
    private function sameRank(array $left, array $right): bool
    {
        return $left['constraint_count'] === $right['constraint_count']
            && $left['mode_priority'] === $right['mode_priority']
            && $left['pattern_length'] === $right['pattern_length'];
    }
}
