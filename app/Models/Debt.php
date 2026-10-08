<?php

namespace App\Models;

use App\Currency;
use App\DebtDirection;
use App\DebtEntryKind;
use App\ExactInteger;
use Carbon\CarbonImmutable;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $counterparty
 * @property DebtDirection $direction
 * @property Currency $currency
 * @property int $opening_balance_minor
 * @property CarbonImmutable $opened_on
 */
#[Fillable(['user_id', 'name', 'counterparty', 'direction', 'currency', 'opening_balance_minor', 'opened_on'])]
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<DebtEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(DebtEntry::class);
    }

    public function balance(): ExactInteger
    {
        $this->loadMissing('entries.transaction');
        $balance = ExactInteger::from($this->opening_balance_minor);
        foreach ($this->entries as $entry) {
            if ($entry->transaction_id !== null && $entry->transaction?->voided_at !== null) {
                continue;
            }
            $amount = ExactInteger::from($entry->amount_minor);
            $balance = $entry->kind === DebtEntryKind::Repayment
                ? $balance->subtract($amount)
                : $balance->add($amount);
        }

        return $balance;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['direction' => DebtDirection::class, 'currency' => Currency::class,
            'opening_balance_minor' => 'integer', 'opened_on' => 'immutable_date'];
    }
}
