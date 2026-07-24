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
])]
final class OpenClawAuditEvent extends Model
{
    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'result_count' => 0,
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
        ];
    }
}
