<?php

namespace App\Models;

use App\Currency;
use App\TransactionKind;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $occurred_on
 * @property int $amount_minor
 * @property Currency $currency
 * @property TransactionKind $kind
 * @property string $merchant_description
 * @property Carbon $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'occurred_on',
    'amount_minor',
    'currency',
    'kind',
    'merchant_description',
    'confirmed_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

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
            'occurred_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'kind' => TransactionKind::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
