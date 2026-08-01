<?php

namespace App\Models;

use App\IntegrationFailureKind;
use App\IntegrationService;
use App\IntegrationWorkType;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property IntegrationService $integration
 * @property IntegrationWorkType $work_type
 * @property string $work_id
 * @property string $source_identity
 * @property IntegrationFailureKind $failure_kind
 * @property string $last_error_code
 * @property int $attempt_count
 * @property int $replay_count
 * @property CarbonImmutable $first_failed_at
 * @property CarbonImmutable $last_failed_at
 * @property CarbonImmutable $visible_at
 * @property CarbonImmutable $retry_until
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $parked_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $recovered_at
 * @property CarbonImmutable|null $last_replayed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 */
#[Fillable([
    'user_id',
    'integration',
    'work_type',
    'work_id',
    'source_identity',
    'failure_kind',
    'last_error_code',
    'attempt_count',
    'replay_count',
    'first_failed_at',
    'last_failed_at',
    'visible_at',
    'retry_until',
    'next_attempt_at',
    'parked_at',
    'acknowledged_at',
    'recovered_at',
    'last_replayed_at',
])]
class IntegrationIncident extends Model
{
    /** @use HasFactory<IntegrationIncidentFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt_count' => 0,
        'replay_count' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'integration' => IntegrationService::class,
            'work_type' => IntegrationWorkType::class,
            'failure_kind' => IntegrationFailureKind::class,
            'attempt_count' => 'integer',
            'replay_count' => 'integer',
            'first_failed_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
            'visible_at' => 'immutable_datetime',
            'retry_until' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'parked_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
            'last_replayed_at' => 'immutable_datetime',
        ];
    }
}
