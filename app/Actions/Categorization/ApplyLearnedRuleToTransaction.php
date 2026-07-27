<?php

namespace App\Actions\Categorization;

use App\CategoryAssignmentProvenance;
use App\Models\CategoryAssignment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class ApplyLearnedRuleToTransaction
{
    public function __construct(private ResolveLearnedRuleForTransaction $resolveLearnedRuleForTransaction) {}

    public function handle(Transaction $transaction): Transaction
    {
        return DB::transaction(function () use ($transaction): Transaction {
            $lockedTransaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->sole();

            if (! CategoryAssignmentProvenance::LearnedRule->canReplace($lockedTransaction->category_assignment_provenance)) {
                return $lockedTransaction;
            }

            $resolution = $this->resolveLearnedRuleForTransaction->handle($lockedTransaction);

            if ($resolution['conflict']) {
                if ($lockedTransaction->category_assignment_provenance !== null) {
                    $lockedTransaction->category_id = null;
                    $lockedTransaction->category_assignment_provenance = null;
                    $lockedTransaction->revision++;
                    $lockedTransaction->save();
                }

                return $lockedTransaction;
            }

            $winner = $resolution['winner'];

            if ($winner === null) {
                return $lockedTransaction;
            }

            $previousCategoryId = $lockedTransaction->category_id;
            $lockedTransaction->category_id = $winner->category_id;
            $lockedTransaction->category_assignment_provenance = CategoryAssignmentProvenance::LearnedRule;
            $lockedTransaction->revision++;
            $lockedTransaction->save();

            CategoryAssignment::create([
                'user_id' => $lockedTransaction->user_id,
                'transaction_id' => $lockedTransaction->id,
                'category_id' => $winner->category_id,
                'previous_category_id' => $previousCategoryId,
                'source' => CategoryAssignmentProvenance::LearnedRule,
                'transaction_revision' => $lockedTransaction->revision,
                'learned_rule_id' => $winner->learned_rule_id,
                'learned_rule_revision' => $winner->revision,
            ]);

            return $lockedTransaction;
        }, 3);
    }
}
