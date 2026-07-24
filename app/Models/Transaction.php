<?php

namespace App\Models;

use App\CategoryAssignmentProvenance;
use App\Currency;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $occurred_on
 * @property int $amount_minor
 * @property Currency $currency
 * @property TransactionKind $kind
 * @property string $merchant_description
 * @property CarbonImmutable $confirmed_at
 * @property int $revision
 * @property list<string> $provisional_fields
 * @property CarbonImmutable|null $voided_at
 * @property int|null $original_purchase_id
 * @property list<string> $refund_relationship_review_reasons
 * @property int|null $category_id
 * @property CategoryAssignmentProvenance|null $category_assignment_provenance
 * @property-read int|string|null $linked_refund_total_minor
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
