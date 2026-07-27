<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyExchangeRateSeedRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $applicable_on
 * @property int $attempt_count
 * @property int $missing_observation_count
 * @property int $transport_failure_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $owner_entry_required_at
 * @property CarbonImmutable|null $retrieval_failed_at
 * @property string|null $last_error_code
 * @property int|null $reminder_id
 * @property string $resolution_idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'applicable_on',
    'attempt_count',
    'missing_observation_count',
    'transport_failure_count',
    'next_attempt_at',
    'queued_at',
    'claimed_at',
    'last_attempted_at',
    'completed_at',
    'owner_entry_required_at',
    'retrieval_failed_at',
    'last_error_code',
    'reminder_id',
    'resolution_idempotency_key',
])]
class DailyExchangeRateSeedRequest extends Model
{
    /** @use HasFactory<DailyExchangeRateSeedRequestFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt_count' => 0,
        'missing_observation_count' => 0,
        'transport_failure_count' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Reminder, $this> */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'applicable_on' => 'immutable_date',
            'attempt_count' => 'integer',
            'missing_observation_count' => 'integer',
            'transport_failure_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'owner_entry_required_at' => 'immutable_datetime',
            'retrieval_failed_at' => 'immutable_datetime',
        ];
    }
}
