<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $grant_id
 * @property int $open_claw_pending_operation_id
 * @property int $user_id
 * @property string $service_key_id
 * @property int $schema_version
 * @property string $payload_digest
 * @property int $pending_operation_revision
 * @property string $approval_interaction_digest
 * @property CarbonImmutable $approval_occurred_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'grant_id',
    'open_claw_pending_operation_id',
    'user_id',
    'service_key_id',
    'schema_version',
    'payload_digest',
    'pending_operation_revision',
    'approval_interaction_digest',
    'approval_occurred_at',
    'expires_at',
    'consumed_at',
])]
final class OpenClawConfirmationGrant extends Model
{
    /**
     * @return BelongsTo<OpenClawPendingOperation, $this>
     */
    public function pendingOperation(): BelongsTo
    {
        return $this->belongsTo(OpenClawPendingOperation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'pending_operation_revision' => 'integer',
            'approval_occurred_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
