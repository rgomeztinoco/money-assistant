<?php

namespace App\Models;

use App\SuspectedDuplicateOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $suspected_duplicate_id
 * @property string $idempotency_key
 * @property SuspectedDuplicateOperation $operation
 * @property int|null $survivor_transaction_id
 * @property int $expected_suspected_duplicate_revision
 * @property int $expected_first_transaction_revision
 * @property int $expected_second_transaction_revision
 * @property string|null $expected_first_source_reference_fingerprint
 * @property string|null $expected_second_source_reference_fingerprint
 * @property string|null $expected_first_receipt_breakdown_fingerprint
 * @property string|null $expected_second_receipt_breakdown_fingerprint
 * @property int $result_suspected_duplicate_revision
 * @property int $result_first_transaction_revision
 * @property int $result_second_transaction_revision
 * @property int|null $result_survivor_transaction_id
 * @property int|null $result_voided_transaction_id
 * @property CarbonImmutable|null $result_resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'suspected_duplicate_id',
    'idempotency_key',
    'operation',
    'survivor_transaction_id',
    'expected_suspected_duplicate_revision',
    'expected_first_transaction_revision',
    'expected_second_transaction_revision',
    'expected_first_source_reference_fingerprint',
    'expected_second_source_reference_fingerprint',
    'expected_first_receipt_breakdown_fingerprint',
    'expected_second_receipt_breakdown_fingerprint',
    'result_suspected_duplicate_revision',
    'result_first_transaction_revision',
    'result_second_transaction_revision',
    'result_survivor_transaction_id',
    'result_voided_transaction_id',
    'result_resolved_at',
])]
class SuspectedDuplicateResolution extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<SuspectedDuplicate, $this>
     */
    public function suspectedDuplicate(): BelongsTo
    {
        return $this->belongsTo(SuspectedDuplicate::class);
    }

    /**
     * @return HasMany<SuspectedDuplicateSourceMove, $this>
     */
    public function sourceMoves(): HasMany
    {
        return $this->hasMany(SuspectedDuplicateSourceMove::class);
    }

    /**
     * @return HasMany<SuspectedDuplicateReceiptBreakdownMove, $this>
     */
    public function receiptBreakdownMoves(): HasMany
    {
        return $this->hasMany(SuspectedDuplicateReceiptBreakdownMove::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => SuspectedDuplicateOperation::class,
            'expected_suspected_duplicate_revision' => 'integer',
            'expected_first_transaction_revision' => 'integer',
            'expected_second_transaction_revision' => 'integer',
            'result_suspected_duplicate_revision' => 'integer',
            'result_first_transaction_revision' => 'integer',
            'result_second_transaction_revision' => 'integer',
            'result_resolved_at' => 'immutable_datetime',
        ];
    }
}
