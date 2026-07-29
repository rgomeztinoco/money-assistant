<?php

namespace App\Models;

use App\AiClassificationOutcome;
use App\CategoryAssignmentProvenance;
use Database\Factories\CategoryAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property int|null $category_id
 * @property int|null $previous_category_id
 * @property CategoryAssignmentProvenance $source
 * @property bool $is_correction
 * @property int $transaction_revision
 * @property int|null $linked_purchase_id
 * @property int|null $learned_rule_id
 * @property int|null $learned_rule_revision
 * @property int|null $learned_rule_bulk_action_id
 * @property string|null $ai_classifier_version
 * @property int|null $ai_confidence
 * @property AiClassificationOutcome|null $ai_outcome
 * @property string|null $ai_explanation
 * @property string|null $ai_taxonomy_fingerprint
 * @property bool|null $ai_requires_review
 * @property Carbon|null $ai_reviewed_at
 * @property bool|null $ai_approved_unchanged
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'category_id',
    'previous_category_id',
    'source',
    'is_correction',
    'transaction_revision',
    'linked_purchase_id',
    'learned_rule_id',
    'learned_rule_revision',
    'learned_rule_bulk_action_id',
    'ai_classifier_version',
    'ai_confidence',
    'ai_outcome',
    'ai_explanation',
    'ai_taxonomy_fingerprint',
    'ai_requires_review',
    'ai_reviewed_at',
    'ai_approved_unchanged',
])]
class CategoryAssignment extends Model
{
    /** @use HasFactory<CategoryAssignmentFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_correction' => false,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function previousCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'previous_category_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function linkedPurchase(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'linked_purchase_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => CategoryAssignmentProvenance::class,
            'is_correction' => 'boolean',
            'transaction_revision' => 'integer',
            'learned_rule_id' => 'integer',
            'learned_rule_revision' => 'integer',
            'learned_rule_bulk_action_id' => 'integer',
            'ai_confidence' => 'integer',
            'ai_outcome' => AiClassificationOutcome::class,
            'ai_requires_review' => 'boolean',
            'ai_reviewed_at' => 'immutable_datetime',
            'ai_approved_unchanged' => 'boolean',
        ];
    }
}
