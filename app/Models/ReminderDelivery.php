<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReminderDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $reminder_id
 * @property string $event_type
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable $occurred_at
 * @property int $attempt_count
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $last_attempted_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $terminal_at
 * @property string|null $terminal_reason
 * @property string|null $last_error_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'id',
    'reminder_id',
    'event_type',
    'scheduled_for',
    'occurred_at',
    'attempt_count',
    'next_attempt_at',
    'queued_at',
    'claimed_at',
    'last_attempted_at',
    'accepted_at',
    'delivered_at',
    'terminal_at',
    'terminal_reason',
    'last_error_code',
])]
final class ReminderDelivery extends Model
{
    /** @use HasFactory<ReminderDeliveryFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'event_type' => 'reminder.due',
        'attempt_count' => 0,
    ];

    /**
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * @param  Builder<ReminderDelivery>  $query
     * @return Builder<ReminderDelivery>
     */
    public function scopeAdmittedOpenClawEvent(Builder $query): Builder
    {
        $cutoff = now()->subMinutes(30);

        return $query
            ->whereNull('terminal_at')
            ->where(fn (Builder $query) => $query
                ->where('accepted_at', '>=', $cutoff)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('accepted_at')
                    ->where('last_attempted_at', '>=', $cutoff))
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('accepted_at')
                    ->whereNull('last_attempted_at')
                    ->where('occurred_at', '>=', $cutoff)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
