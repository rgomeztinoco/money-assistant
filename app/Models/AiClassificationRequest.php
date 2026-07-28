<?php

namespace App\Models;

use App\AiClassificationError;
use App\AiClassificationOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\AiClassificationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property int $expected_transaction_revision
 * @property int $attempt_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property CarbonImmutable|null $completed_at
 * @property AiClassificationOutcome|null $terminal_outcome
 * @property AiClassificationError|null $last_error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Transaction $transaction
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'expected_transaction_revision',
    'attempt_count',
    'next_attempt_at',
    'queued_at',
    'claimed_at',
    'last_attempted_at',
    'completed_at',
    'terminal_outcome',
    'last_error_code',
])]
class AiClassificationRequest extends Model
{
    /** @use HasFactory<AiClassificationRequestFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt_count' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_transaction_revision' => 'integer',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'terminal_outcome' => AiClassificationOutcome::class,
            'last_error_code' => AiClassificationError::class,
        ];
    }
}
