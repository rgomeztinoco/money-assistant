<?php

namespace App\Models;

use App\TransactionVoidOperation;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionStateChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property string $idempotency_key
 * @property TransactionVoidOperation $operation
 * @property int $expected_revision
 * @property int $result_revision
 * @property CarbonImmutable|null $result_voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'idempotency_key',
    'operation',
    'expected_revision',
    'result_revision',
    'result_voided_at',
])]
class TransactionStateChange extends Model
{
    /** @use HasFactory<TransactionStateChangeFactory> */
    use HasFactory;

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
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => TransactionVoidOperation::class,
            'expected_revision' => 'integer',
            'result_revision' => 'integer',
            'result_voided_at' => 'immutable_datetime',
        ];
    }
}
