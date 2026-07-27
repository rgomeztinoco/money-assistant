<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReminderLifecycleEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reminder_id
 * @property string $service_key_id
 * @property int $schema_version
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property string|null $interaction_digest
 * @property string $action
 * @property string|null $domain_action
 * @property int $reminder_revision
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $snoozed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'reminder_id',
    'service_key_id',
    'schema_version',
    'idempotency_key',
    'payload_digest',
    'interaction_digest',
    'action',
    'domain_action',
    'reminder_revision',
    'occurred_at',
    'snoozed_until',
])]
final class ReminderLifecycleEvent extends Model
{
    /** @use HasFactory<ReminderLifecycleEventFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'reminder_revision' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
        ];
    }
}
