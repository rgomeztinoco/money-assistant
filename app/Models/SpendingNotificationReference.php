<?php

namespace App\Models;

use Database\Factories\SpendingNotificationReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $transaction_id
 * @property int|null $spending_notification_format_id
 * @property string $gmail_account_identity
 * @property string $message_id
 * @property string $processing_outcome
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'gmail_account_identity',
    'message_id',
    'processing_outcome',
    'spending_notification_format_id',
])]
class SpendingNotificationReference extends Model
{
    /** @use HasFactory<SpendingNotificationReferenceFactory> */
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

    /** @return BelongsTo<SpendingNotificationFormat, $this> */
    public function format(): BelongsTo
    {
        return $this->belongsTo(SpendingNotificationFormat::class, 'spending_notification_format_id');
    }
}
