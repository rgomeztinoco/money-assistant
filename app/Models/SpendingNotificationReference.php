<?php

namespace App\Models;

use App\SpendingNotificationProcessingOutcome;
use Database\Factories\SpendingNotificationReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $transaction_id
 * @property int|null $spending_notification_format_id
 * @property int|null $gmail_message_discovery_id
 * @property string $gmail_account_identity
 * @property string $message_id
 * @property string $processing_outcome
 * @property int $attempt_count
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transaction_id',
    'gmail_account_identity',
    'message_id',
    'processing_outcome',
    'spending_notification_format_id',
    'gmail_message_discovery_id',
    'attempt_count',
    'last_attempted_at',
])]
class SpendingNotificationReference extends Model
{
    /** @use HasFactory<SpendingNotificationReferenceFactory> */
    use HasFactory;

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

    /** @return BelongsTo<GmailMessageDiscovery, $this> */
    public function discovery(): BelongsTo
    {
        return $this->belongsTo(GmailMessageDiscovery::class, 'gmail_message_discovery_id');
    }

    public function isRetryable(): bool
    {
        return $this->transaction_id === null
            && SpendingNotificationProcessingOutcome::tryFrom($this->processing_outcome)
                ?->isRetryable() === true;
    }

    public function isRecoverable(): bool
    {
        return $this->transaction_id === null
            && SpendingNotificationProcessingOutcome::tryFrom($this->processing_outcome)
                ?->isRecoverable() === true;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
        ];
    }
}
