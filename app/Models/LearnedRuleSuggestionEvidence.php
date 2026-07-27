<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $learned_rule_suggestion_id
 * @property int $category_assignment_id
 * @property int $transaction_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LearnedRuleSuggestion $suggestion
 * @property-read CategoryAssignment $categoryAssignment
 * @property-read Transaction $transaction
 */
#[Fillable(['learned_rule_suggestion_id', 'category_assignment_id', 'transaction_id'])]
class LearnedRuleSuggestionEvidence extends Model
{
    /** @return BelongsTo<LearnedRuleSuggestion, $this> */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(LearnedRuleSuggestion::class, 'learned_rule_suggestion_id');
    }

    /** @return BelongsTo<CategoryAssignment, $this> */
    public function categoryAssignment(): BelongsTo
    {
        return $this->belongsTo(CategoryAssignment::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
