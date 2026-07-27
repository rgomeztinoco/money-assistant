<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\Models\Category;
use App\Models\LearnedRule;
use App\Models\LearnedRuleChangePreview;
use App\Models\LearnedRuleSuggestion;
use App\Models\User;
use App\TransactionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmLearnedRuleChange
{
    public function __construct(private AnalyzeLearnedRuleDefinition $analyzeLearnedRuleDefinition) {}

    public function handle(User $owner, int $previewId, ?int $learnedRuleId = null): LearnedRule
    {
        return DB::transaction(function () use ($owner, $previewId, $learnedRuleId): LearnedRule {
            User::query()->whereKey($owner->id)->lockForUpdate()->sole();

            $preview = LearnedRuleChangePreview::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($previewId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($preview->confirmed_at !== null || $preview->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'preview_id' => 'This Learned Rule preview is no longer available. Prepare a new preview.',
                ]);
            }

            if ($learnedRuleId !== $preview->learned_rule_id) {
                throw ValidationException::withMessages([
                    'preview_id' => 'Confirm this preview for the Learned Rule it describes.',
                ]);
            }

            $rule = $preview->learned_rule_id === null
                ? null
                : LearnedRule::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($preview->learned_rule_id)
                    ->lockForUpdate()
                    ->firstOrFail();

            if ($rule !== null
                && ($rule->retired_at !== null || $rule->revision !== $preview->expected_rule_revision)) {
                throw ValidationException::withMessages([
                    'preview_id' => 'This Learned Rule changed after the preview. Prepare a new preview.',
                ]);
            }

            $definition = $preview->definition;
            $category = Category::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($definition['category_id'])
                ->whereNull('retired_at')
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                throw ValidationException::withMessages([
                    'preview_id' => 'The previewed Category is no longer active.',
                ]);
            }

            $analysis = $this->analyzeLearnedRuleDefinition->handle(
                owner: $owner,
                category: $category,
                merchantPattern: $definition['merchant_pattern'],
                matchMode: LearnedRuleMatchMode::from($definition['match_mode']),
                transactionKind: $definition['transaction_kind'] === null ? null : TransactionKind::from($definition['transaction_kind']),
                currency: $definition['currency'] === null ? null : Currency::from($definition['currency']),
                paymentInstrumentLabel: $definition['payment_instrument_label'],
                paymentInstrumentLastFour: $definition['payment_instrument_last_four'],
                revisedRule: $rule,
            );

            if ($analysis['resource_fingerprint'] !== $preview->resource_fingerprint) {
                throw ValidationException::withMessages([
                    'preview_id' => 'Matches or overlapping rules changed after the preview. Review the current effect and try again.',
                ]);
            }

            if ($analysis['blocked']) {
                throw ValidationException::withMessages([
                    'preview_id' => 'Resolve equally specific rules with conflicting Categories before confirmation.',
                ]);
            }

            $rule ??= LearnedRule::create([
                'user_id' => $owner->id,
                'activated_at' => now(),
            ]);
            $revision = $preview->expected_rule_revision === null
                ? 1
                : $preview->expected_rule_revision + 1;
            $rule->revisions()->create([
                'revision' => $revision,
                'category_id' => $definition['category_id'],
                'merchant_pattern' => $definition['merchant_pattern'],
                'merchant_key' => $definition['merchant_key'],
                'match_mode' => $definition['match_mode'],
                'transaction_kind' => $definition['transaction_kind'],
                'currency' => $definition['currency'],
                'payment_instrument_label' => $definition['payment_instrument_label'],
                'payment_instrument_last_four' => $definition['payment_instrument_last_four'],
                'source_category_assignment_id' => $preview->source_category_assignment_id,
            ]);

            if ($rule->revision !== $revision) {
                $rule->revision = $revision;
                $rule->save();
            }

            $preview->confirmed_at = now();
            $preview->save();

            if ($preview->learned_rule_suggestion_id !== null) {
                $suggestion = LearnedRuleSuggestion::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($preview->learned_rule_suggestion_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($suggestion->status !== LearnedRuleSuggestionStatus::Pending) {
                    throw ValidationException::withMessages([
                        'preview_id' => 'This Learned Rule suggestion changed after the preview.',
                    ]);
                }

                $suggestion->status = LearnedRuleSuggestionStatus::Accepted;
                $suggestion->accepted_rule_id = $rule->id;
                $suggestion->save();
            }

            return $rule;
        }, 3);
    }
}
