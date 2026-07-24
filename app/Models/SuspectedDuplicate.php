<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SuspectedDuplicateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $first_transaction_id
 * @property int $second_transaction_id
 * @property int $revision
 * @property int|null $survivor_transaction_id
 * @property int|null $voided_transaction_id
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'first_transaction_id',
    'second_transaction_id',
    'revision',
    'survivor_transaction_id',
    'voided_transaction_id',
    'resolved_at',
])]
class SuspectedDuplicate extends Model
{
    /** @use HasFactory<SuspectedDuplicateFactory> */
    use HasFactory;

    protected $attributes = [
        'revision' => 1,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function firstTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'first_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function secondTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'second_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function survivorTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'survivor_transaction_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function voidedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'voided_transaction_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
