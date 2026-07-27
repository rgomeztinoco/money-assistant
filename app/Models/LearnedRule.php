<?php

namespace App\Models;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Database\Factories\LearnedRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $revision
 * @property CarbonImmutable $activated_at
 * @property CarbonImmutable|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Collection<int, LearnedRuleRevision> $revisions
 * @property-read LearnedRuleRevision|null $currentRevision
 */
#[Fillable(['user_id', 'revision', 'activated_at', 'retired_at'])]
class LearnedRule extends Model
{
    /** @use HasFactory<LearnedRuleFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['revision' => 1];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<LearnedRuleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(LearnedRuleRevision::class);
    }

    /** @return HasOne<LearnedRuleRevision, $this> */
    public function currentRevision(): HasOne
    {
        return $this->hasOne(LearnedRuleRevision::class)->ofMany('revision', 'max');
    }

    /**
     * @param  Builder<LearnedRule>  $query
     * @return Builder<LearnedRule>
     */
    public function scopeMatchingCurrentDefinition(
        Builder $query,
        int $ownerId,
        int $categoryId,
        string $merchantKey,
        LearnedRuleMatchMode $matchMode,
        ?TransactionKind $transactionKind,
        ?Currency $currency,
        ?string $paymentInstrumentLabel,
        ?string $paymentInstrumentLastFour,
    ): Builder {
        return $query
            ->where('user_id', $ownerId)
            ->whereNull('retired_at')
            ->whereHas('currentRevision', fn (Builder $revisionQuery) => $revisionQuery
                ->where('category_id', $categoryId)
                ->where('merchant_key', $merchantKey)
                ->where('match_mode', $matchMode)
                ->where('transaction_kind', $transactionKind)
                ->where('currency', $currency)
                ->where('payment_instrument_label', $paymentInstrumentLabel)
                ->where('payment_instrument_last_four', $paymentInstrumentLastFour));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
