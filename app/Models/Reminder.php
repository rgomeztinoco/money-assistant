<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $subject
 * @property CarbonImmutable $scheduled_for
 * @property int $revision
 * @property CarbonImmutable|null $acknowledged_at
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'subject',
    'scheduled_for',
    'revision',
    'acknowledged_at',
    'snoozed_until',
    'dismissed_at',
    'resolved_at',
])]
final class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
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
     * @return HasMany<ReminderDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReminderDelivery::class);
    }

    /**
     * @return HasMany<ReminderLifecycleEvent, $this>
     */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ReminderLifecycleEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'revision' => 'integer',
            'acknowledged_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
