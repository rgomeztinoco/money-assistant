<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string $service_key_id
 * @property int|null $schema_version
 * @property string|null $capability
 * @property string $outcome
 * @property int $http_status
 * @property string $nonce_digest
 * @property string $request_digest
 * @property string|null $interaction_digest
 * @property string|null $resource_type
 * @property int $result_count
 * @property string $event_kind
 * @property string|null $idempotency_key
 * @property string|null $operation_digest
 * @property string|null $confirmation_grant_id
 * @property string|null $domain_action
 * @property int|null $resource_id
 * @property int|null $resource_revision
 */
#[Fillable([
    'occurred_at',
    'service_key_id',
    'schema_version',
    'capability',
    'outcome',
    'http_status',
    'nonce_digest',
    'request_digest',
    'interaction_digest',
    'resource_type',
    'result_count',
    'event_kind',
    'idempotency_key',
    'operation_digest',
    'confirmation_grant_id',
    'domain_action',
    'resource_id',
    'resource_revision',
])]
final class OpenClawAuditEvent extends Model
{
    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'result_count' => 0,
        'event_kind' => 'request',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'schema_version' => 'integer',
            'http_status' => 'integer',
            'result_count' => 'integer',
            'resource_id' => 'integer',
            'resource_revision' => 'integer',
        ];
    }
}
