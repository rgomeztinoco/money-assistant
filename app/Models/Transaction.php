<?php

namespace App\Models;

use App\AiClassificationOutcome;
use App\CategoryAssignmentProvenance;
use App\Currency;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $occurred_on
 * @property int $amount_minor
 * @property Currency $currency
 * @property TransactionKind $kind
 * @property string $merchant_description
 * @property string|null $payment_instrument_label
 * @property string|null $payment_instrument_last_four
 * @property CarbonImmutable $confirmed_at
 * @property int $revision
 * @property list<string> $provisional_fields
 * @property CarbonImmutable|null $voided_at
 * @property string|null $deployment_rehearsal_id
 * @property int|null $original_purchase_id
 * @property list<string> $refund_relationship_review_reasons
 * @property int|null $category_id
 * @property CategoryAssignmentProvenance|null $category_assignment_provenance
 * @property-read int|string|null $linked_refund_total_minor
 * @property-read bool $linked_refunds_exists
 * @property-read bool $receipt_breakdowns_exists
 * @property-read bool $resolved_duplicate_relationships_as_survivor_exists
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'occurred_on',
    'amount_minor',
    'currency',
    'kind',
    'merchant_description',
    'payment_instrument_label',
    'payment_instrument_last_four',
    'confirmed_at',
    'revision',
    'provisional_fields',
    'voided_at',
    'original_purchase_id',
    'refund_relationship_review_reasons',
    'category_id',
    'category_assignment_provenance',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
        'provisional_fields' => '[]',
        'refund_relationship_review_reasons' => '[]',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<TransactionCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(TransactionCorrection::class);
    }

    /**
     * @return HasMany<TransactionStateChange, $this>
     */
    public function stateChanges(): HasMany
    {
        return $this->hasMany(TransactionStateChange::class);
    }

    /**
     * @return HasMany<SpendingNotificationReference, $this>
     */
    public function spendingNotificationReferences(): HasMany
    {
        return $this->hasMany(SpendingNotificationReference::class);
    }

    /**
     * @return HasMany<SuspectedDuplicate, $this>
     */
    public function resolvedDuplicateRelationshipsAsSurvivor(): HasMany
    {
        return $this->hasMany(SuspectedDuplicate::class, 'survivor_transaction_id')
            ->whereNotNull('resolved_at');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function originalPurchase(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_purchase_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function linkedRefunds(): HasMany
    {
        return $this->hasMany(self::class, 'original_purchase_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<CategoryAssignment, $this>
     */
    public function categoryAssignments(): HasMany
    {
        return $this->hasMany(CategoryAssignment::class);
    }

    /**
     * @return HasOne<CategoryAssignment, $this>
     */
    public function currentCategoryAssignment(): HasOne
    {
        return $this->hasOne(CategoryAssignment::class)->ofMany('transaction_revision', 'max');
    }

    /** @return HasOne<AiCategoryProposal, $this> */
    public function aiCategoryProposal(): HasOne
    {
        return $this->hasOne(AiCategoryProposal::class);
    }

    public function hasProvisionalAiCategory(): bool
    {
        return $this->currentCategoryAssignment?->source === CategoryAssignmentProvenance::Ai
            && ($this->currentCategoryAssignment->ai_outcome === AiClassificationOutcome::Medium
                || $this->currentCategoryAssignment->ai_outcome === AiClassificationOutcome::High)
            && $this->currentCategoryAssignment->ai_requires_review !== false;
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereHasProvisionalAiCategory(Builder $query): Builder
    {
        return $query->whereHas(
            'currentCategoryAssignment',
            fn (Builder $query) => $query->whereRequiresAiReview(),
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereDoesntHaveProvisionalAiCategory(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'currentCategoryAssignment',
            fn (Builder $query) => $query->whereRequiresAiReview(),
        );
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereCategoryRequiresReview(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('category_id')
                        ->whereDoesntHave('receiptBreakdowns', fn (Builder $query) => $query
                            ->where('status', 'confirmed')
                            ->whereHas('lineItems'));
                })
                ->orWhere(fn (Builder $query) => $query->whereHasProvisionalAiCategory());
        });
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereRequiresReview(Builder $query): Builder
    {
        $suspectedDuplicatesTable = (new SuspectedDuplicate)->getTable();
        $transactionsTable = $query->getModel()->getTable();

        return $query->where(function (Builder $query) use ($suspectedDuplicatesTable, $transactionsTable): void {
            $query
                ->where(fn (Builder $query) => $query->whereCategoryRequiresReview())
                ->orWhereHas('receiptBreakdowns', fn (Builder $query) => $query
                    ->where('status', 'confirmed')
                    ->whereHas('lineItems', fn (Builder $query) => $query->whereNull('category_id')))
                ->orWhereJsonLength('provisional_fields', '>', 0)
                ->orWhereJsonLength('refund_relationship_review_reasons', '>', 0)
                ->orWhereExists(function (QueryBuilder $query) use ($suspectedDuplicatesTable, $transactionsTable): void {
                    $query
                        ->selectRaw('1')
                        ->from($suspectedDuplicatesTable)
                        ->whereColumn($suspectedDuplicatesTable.'.user_id', $transactionsTable.'.user_id')
                        ->where(function (QueryBuilder $query) use ($suspectedDuplicatesTable, $transactionsTable): void {
                            $query
                                ->whereColumn($suspectedDuplicatesTable.'.first_transaction_id', $transactionsTable.'.id')
                                ->orWhereColumn($suspectedDuplicatesTable.'.second_transaction_id', $transactionsTable.'.id');
                        })
                        ->whereNull($suspectedDuplicatesTable.'.resolved_at');
                });
        });
    }

    /**
     * @return HasMany<ReceiptBreakdown, $this>
     */
    public function receiptBreakdowns(): HasMany
    {
        return $this->hasMany(ReceiptBreakdown::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'kind' => TransactionKind::class,
            'confirmed_at' => 'immutable_datetime',
            'revision' => 'integer',
            'provisional_fields' => 'array',
            'voided_at' => 'immutable_datetime',
            'refund_relationship_review_reasons' => 'array',
            'category_assignment_provenance' => CategoryAssignmentProvenance::class,
        ];
    }
}
