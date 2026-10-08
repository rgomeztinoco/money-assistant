<?php

namespace App\Models;

use App\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\YearlyPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $amount_minor
 * @property Currency $currency
 * @property int $cushion_minor
 * @property CarbonImmutable|null $expected_due_on
 * @property bool $is_active
 */
#[Fillable(['user_id', 'name', 'amount_minor', 'currency', 'cushion_minor', 'expected_due_on', 'is_active'])]
class YearlyPayment extends Model
{
    /** @use HasFactory<YearlyPaymentFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'cushion_minor' => 'integer',
            'expected_due_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
