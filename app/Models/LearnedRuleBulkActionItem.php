<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $learned_rule_bulk_action_id
 * @property int $transaction_id
 * @property int $expected_transaction_revision
 * @property int|null $previous_category_id
 * @property int|null $applied_transaction_revision
 * @property int|null $undo_transaction_revision
 * @property string $status
 */
#[Fillable([
    'learned_rule_bulk_action_id',
    'transaction_id',
    'expected_transaction_revision',
    'previous_category_id',
    'applied_transaction_revision',
    'undo_transaction_revision',
    'status',
])]
class LearnedRuleBulkActionItem extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return BelongsTo<LearnedRuleBulkAction, $this> */
    public function bulkAction(): BelongsTo
    {
        return $this->belongsTo(LearnedRuleBulkAction::class, 'learned_rule_bulk_action_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function previousCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'previous_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_transaction_revision' => 'integer',
            'applied_transaction_revision' => 'integer',
            'undo_transaction_revision' => 'integer',
        ];
    }
}
