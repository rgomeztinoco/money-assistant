<?php

namespace App\Models;

use App\DebtEntryKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $debt_id
 * @property int|null $transaction_id
 * @property DebtEntryKind $kind
 * @property int $amount_minor
 * @property CarbonImmutable $occurred_on
 * @property string|null $reason
 */
#[Fillable(['debt_id', 'transaction_id', 'kind', 'amount_minor', 'occurred_on', 'reason'])]
class DebtEntry extends Model
{
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
        return ['kind' => DebtEntryKind::class, 'amount_minor' => 'integer', 'occurred_on' => 'immutable_date'];
    }
}
