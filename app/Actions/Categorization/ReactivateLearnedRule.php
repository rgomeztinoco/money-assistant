<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReactivateLearnedRule
{
    public function __construct(private AnalyzeLearnedRuleDefinition $analyzeLearnedRuleDefinition) {}

    public function handle(User $owner, int $ruleId, int $expectedRevision): LearnedRule
    {
        return DB::transaction(function () use ($owner, $ruleId, $expectedRevision): LearnedRule {
            User::query()->whereKey($owner->id)->lockForUpdate()->sole();

            $rule = LearnedRule::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($ruleId)
                ->with('currentRevision')
                ->lockForUpdate()
                ->firstOrFail();

            if ($rule->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Learned Rule changed after you reviewed it.',
                ]);
            }

            if ($rule->retired_at === null) {
                return $rule;
            }

            $revision = $rule->currentRevision;
            $category = $revision === null
                ? null
                : Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($revision->category_id)
                    ->whereNull('retired_at')
                    ->lockForUpdate()
                    ->first();

            if ($revision === null || $category === null) {
                throw ValidationException::withMessages([
                    'learned_rule' => 'Reactivate the target Category before this Learned Rule.',
                ]);
            }

            $analysis = $this->analyzeLearnedRuleDefinition->handle(
                $owner,
                $category,
                $revision->merchant_pattern,
                LearnedRuleMatchMode::from($revision->match_mode->value),
                $revision->transaction_kind === null ? null : TransactionKind::from($revision->transaction_kind->value),
                $revision->currency === null ? null : Currency::from($revision->currency->value),
                $revision->payment_instrument_label,
                $revision->payment_instrument_last_four,
                $rule,
            );

            if ($analysis['blocked']) {
                throw ValidationException::withMessages([
                    'learned_rule' => 'Resolve the equally specific conflicting active rule before reactivation.',
                ]);
            }

            $rule->retired_at = null;
            $rule->activated_at = now();
            $rule->save();

            return $rule;
        }, 3);
    }
}
