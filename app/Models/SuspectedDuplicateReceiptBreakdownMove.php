<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $suspected_duplicate_resolution_id
 * @property int $receipt_breakdown_id
 * @property int $from_transaction_id
 * @property int $to_transaction_id
 * @property int $receipt_breakdown_revision
 * @property string $receipt_breakdown_status
 */
#[Fillable([
    'suspected_duplicate_resolution_id',
    'receipt_breakdown_id',
    'from_transaction_id',
    'to_transaction_id',
    'receipt_breakdown_revision',
    'receipt_breakdown_status',
])]
class SuspectedDuplicateReceiptBreakdownMove extends Model
{
    /** @return BelongsTo<SuspectedDuplicateResolution, $this> */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            SuspectedDuplicateResolution::class,
            'suspected_duplicate_resolution_id',
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'receipt_breakdown_id' => 'integer',
            'from_transaction_id' => 'integer',
            'to_transaction_id' => 'integer',
            'receipt_breakdown_revision' => 'integer',
        ];
    }
}
