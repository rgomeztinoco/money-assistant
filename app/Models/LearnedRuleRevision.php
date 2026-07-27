<?php

namespace App\Models;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\TransactionKind;
use Database\Factories\LearnedRuleRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $learned_rule_id
 * @property int $revision
 * @property int $category_id
 * @property string $merchant_pattern
 * @property string $merchant_key
 * @property LearnedRuleMatchMode $match_mode
 * @property TransactionKind|null $transaction_kind
 * @property Currency|null $currency
 * @property string|null $payment_instrument_label
 * @property string|null $payment_instrument_last_four
 * @property int|null $source_category_assignment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LearnedRule $learnedRule
 * @property-read Category $category
 * @property-read CategoryAssignment|null $sourceCategoryAssignment
 */
#[Fillable([
    'learned_rule_id',
    'revision',
    'category_id',
    'merchant_pattern',
    'merchant_key',
    'match_mode',
    'transaction_kind',
    'currency',
    'payment_instrument_label',
    'payment_instrument_last_four',
    'source_category_assignment_id',
])]
class LearnedRuleRevision extends Model
{
    /** @use HasFactory<LearnedRuleRevisionFactory> */
    use HasFactory;

    /** @return BelongsTo<LearnedRule, $this> */
    public function learnedRule(): BelongsTo
    {
        return $this->belongsTo(LearnedRule::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<CategoryAssignment, $this> */
    public function sourceCategoryAssignment(): BelongsTo
    {
        return $this->belongsTo(CategoryAssignment::class, 'source_category_assignment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'match_mode' => LearnedRuleMatchMode::class,
            'transaction_kind' => TransactionKind::class,
            'currency' => Currency::class,
        ];
    }
}
