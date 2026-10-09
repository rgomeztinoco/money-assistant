<?php

namespace App\Models;

use App\DebtEntryKind;
use Carbon\CarbonImmutable;
use Database\Factories\DebtEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $debt_id
 * @property int|null $confirmed_with_transaction_id
 * @property int|null $transaction_id
 * @property DebtEntryKind $kind
 * @property int|null $principal_minor
 * @property int $interest_minor
 * @property int $amount_minor
 * @property CarbonImmutable $occurred_on
 * @property string|null $reason
 */
#[Fillable(['debt_id', 'transaction_id', 'confirmed_with_transaction_id', 'kind', 'amount_minor', 'principal_minor', 'interest_minor', 'occurred_on', 'reason'])]
class DebtEntry extends Model
{
    /** @use HasFactory<DebtEntryFactory> */
    use HasFactory;

    /** @return BelongsTo<Debt, $this> */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => DebtEntryKind::class, 'amount_minor' => 'integer', 'principal_minor' => 'integer', 'interest_minor' => 'integer', 'occurred_on' => 'immutable_date'];
    }
}
