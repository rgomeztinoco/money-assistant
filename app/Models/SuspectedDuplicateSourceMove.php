<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'suspected_duplicate_resolution_id',
    'spending_notification_reference_id',
    'from_transaction_id',
    'to_transaction_id',
])]
class SuspectedDuplicateSourceMove extends Model
{
    /**
     * @return BelongsTo<SuspectedDuplicateResolution, $this>
     */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(
            SuspectedDuplicateResolution::class,
            'suspected_duplicate_resolution_id',
        );
    }

    /**
     * @return BelongsTo<SpendingNotificationReference, $this>
     */
    public function spendingNotificationReference(): BelongsTo
    {
        return $this->belongsTo(SpendingNotificationReference::class);
    }
}
