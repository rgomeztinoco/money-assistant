<?php

namespace App\Models;

use Database\Factories\YearlyPaymentSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property numeric-string|null $pen_per_usd
 */
#[Fillable(['user_id', 'pen_per_usd'])]
class YearlyPaymentSetting extends Model
{
    /** @use HasFactory<YearlyPaymentSettingFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
