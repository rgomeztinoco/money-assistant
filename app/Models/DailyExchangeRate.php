<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyExchangeRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $applicable_on
 * @property int $pen_per_usd_scaled
 * @property CarbonImmutable|null $owner_managed_at
 * @property string|null $source
 * @property string|null $source_series
 * @property CarbonImmutable|null $source_observed_on
 * @property CarbonImmutable|null $source_retrieved_at
 * @property string|null $source_value
 * @property int|null $source_precision
 * @property int $revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'applicable_on',
    'pen_per_usd_scaled',
    'owner_managed_at',
    'source',
    'source_series',
    'source_observed_on',
    'source_retrieved_at',
    'source_value',
    'source_precision',
    'revision',
])]
class DailyExchangeRate extends Model
{
    /** @use HasFactory<DailyExchangeRateFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'revision' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function penPerUsd(): string
    {
        $scaled = str_pad((string) $this->pen_per_usd_scaled, 7, '0', STR_PAD_LEFT);

        return substr($scaled, 0, -6).'.'.substr($scaled, -6);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'applicable_on' => 'immutable_date',
            'pen_per_usd_scaled' => 'integer',
            'owner_managed_at' => 'immutable_datetime',
            'source_observed_on' => 'immutable_date',
            'source_retrieved_at' => 'immutable_datetime',
            'source_precision' => 'integer',
            'revision' => 'integer',
        ];
    }
}
