<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @phpstan-type ManualTransactionPayload array{occurred_on: string, amount_minor: int, currency: string, kind: string, merchant_description: string}
 * @phpstan-type CategoryCreatePayload array{operation: 'create', name: string, parent_id: int|null, parent_name: string|null, parent_revision: int|null, description: string|null, examples: list<string>}
 * @phpstan-type CategoryUpdatePayload array{operation: 'update', category_id: int, expected_revision: int, name: string, parent_id: int|null, parent_name: string|null, parent_revision: int|null, description: string|null, examples: list<string>}
 * @phpstan-type CategoryLifecyclePayload array{operation: 'retire'|'reactivate', category_id: int, expected_revision: int, category_name: string, parent_id: int|null, parent_revision: int|null}
 * @phpstan-type CategoryAssignmentPayload array{operation: 'assign_transaction', transaction_id: int, expected_revision: int, category_id: int|null, category_name: string|null, category_revision: int|null}
 * @phpstan-type ReceiptBreakdownPayload array{operation: 'update_draft'|'confirm_draft', receipt_breakdown_id: int, expected_revision: int, transaction_id: int, transaction_revision: int, line_items?: list<array{id: string|null, description: string, line_total_minor: int, category_id: int|null}>, category_revisions?: list<array{id: int, revision: int}>}
 *
 * @property int $id
 * @property int $user_id
 * @property string $operation_id
 * @property string $service_key_id
 * @property int $schema_version
 * @property string $capability
 * @property string $conversation_digest
 * @property string $idempotency_key
 * @property string $payload_digest
 * @property ManualTransactionPayload|CategoryCreatePayload|CategoryUpdatePayload|CategoryLifecyclePayload|CategoryAssignmentPayload|ReceiptBreakdownPayload $payload
 * @property string $effect_summary
 * @property int $prepared_revision
 * @property int $revision
 * @property string $preparation_interaction_digest
 * @property CarbonImmutable $preparation_occurred_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'operation_id',
    'service_key_id',
    'schema_version',
    'capability',
    'conversation_digest',
    'idempotency_key',
    'payload_digest',
    'payload',
    'effect_summary',
    'prepared_revision',
    'revision',
    'preparation_interaction_digest',
    'preparation_occurred_at',
    'expires_at',
    'canceled_at',
    'confirmed_at',
])]
final class OpenClawPendingOperation extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'prepared_revision' => 1,
        'revision' => 1,
    ];

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
            'payload' => 'array',
            'prepared_revision' => 'integer',
            'revision' => 'integer',
            'preparation_occurred_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
