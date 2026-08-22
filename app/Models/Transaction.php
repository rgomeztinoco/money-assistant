<?php

namespace App\Models;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\IncomeSource;
use App\MovementDirection;
use App\TransactionKind;
use App\TransferPurpose;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $occurred_on
 * @property int $amount_minor
 * @property Currency $currency
 * @property TransactionKind $kind
 * @property MovementDirection $direction
 * @property IncomeSource|null $income_source
 * @property TransferPurpose|null $transfer_purpose
 * @property string $description
 * @property string|null $instrument_label
 * @property string|null $instrument_last_four
 * @property CarbonImmutable $confirmed_at
 * @property list<string> $provisional_fields
 * @property CarbonImmutable|null $voided_at
 * @property int|null $original_spending_id
 * @property list<string> $refund_relationship_review_reasons
 * @property int|null $category_id
 * @property CategoryAssignmentProvenance|null $category_assignment_provenance
 * @property int|null $merchant_rule_id
 * @property-read int|string|null $linked_refund_total_minor
 * @property-read bool $linked_refunds_exists
 * @property-read bool $receipt_breakdowns_exists
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'occurred_on',
    'amount_minor',
    'currency',
    'kind',
    'direction',
    'income_source',
    'transfer_purpose',
    'description',
    'instrument_label',
    'instrument_last_four',
    'confirmed_at',
    'provisional_fields',
    'voided_at',
    'original_spending_id',
    'refund_relationship_review_reasons',
    'category_id',
    'category_assignment_provenance',
    'merchant_rule_id',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
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
     * @return HasMany<SpendingNotificationReference, $this>
     */
    public function spendingNotificationReferences(): HasMany
    {
        return $this->hasMany(SpendingNotificationReference::class);
    }

    /** @return HasOne<StatementMovement, $this> */
    public function statementMovement(): HasOne
    {
        return $this->hasOne(StatementMovement::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function originalSpending(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_spending_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function linkedRefunds(): HasMany
    {
        return $this->hasMany(self::class, 'original_spending_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<MerchantRule, $this> */
    public function merchantRule(): BelongsTo
    {
        return $this->belongsTo(MerchantRule::class);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereCategoryRequiresReview(Builder $query): Builder
    {
        return $query
            ->whereIn('kind', [TransactionKind::Spending, TransactionKind::Refund])
            ->whereNull('category_id')
            ->whereDoesntHave('receiptBreakdown', fn (Builder $query) => $query
                ->whereHas('lineItems'));
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWhereRequiresReview(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(fn (Builder $query) => $query->whereCategoryRequiresReview())
                ->orWhereHas('receiptBreakdown', fn (Builder $query) => $query
                    ->whereHas('lineItems', fn (Builder $query) => $query->whereNull('category_id')))
                ->orWhereJsonLength('provisional_fields', '>', 0)
                ->orWhereJsonLength('refund_relationship_review_reasons', '>', 0);
        });
    }

    /**
     * @return HasOne<ReceiptBreakdown, $this>
     */
    public function receiptBreakdown(): HasOne
    {
        return $this->hasOne(ReceiptBreakdown::class);
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
            'direction' => MovementDirection::class,
            'income_source' => IncomeSource::class,
            'transfer_purpose' => TransferPurpose::class,
            'confirmed_at' => 'immutable_datetime',
            'provisional_fields' => 'array',
            'voided_at' => 'immutable_datetime',
            'refund_relationship_review_reasons' => 'array',
            'category_assignment_provenance' => CategoryAssignmentProvenance::class,
        ];
    }
}
