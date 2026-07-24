<?php

namespace App\Models;

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
        ];
    }
}
