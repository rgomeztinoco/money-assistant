<?php

namespace App\Models;

use App\Currency;
use App\LearnedRuleMatchMode;
use App\LearnedRuleSuggestionStatus;
use App\TransactionKind;
use Carbon\CarbonImmutable;
use Database\Factories\LearnedRuleSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $merchant_pattern
 * @property string $merchant_key
 * @property LearnedRuleMatchMode $match_mode
 * @property TransactionKind|null $transaction_kind
 * @property Currency|null $currency
 * @property string|null $payment_instrument_label
 * @property string|null $payment_instrument_last_four
 * @property string $definition_hash
 * @property LearnedRuleSuggestionStatus $status
 * @property int $evidence_count
 * @property CarbonImmutable|null $dismissed_at
 * @property int|null $accepted_rule_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Category $category
 * @property-read Collection<int, LearnedRuleSuggestionEvidence> $evidence
 */
#[Fillable([
    'user_id',
    'category_id',
    'merchant_pattern',
    'merchant_key',
    'match_mode',
    'transaction_kind',
    'currency',
    'payment_instrument_label',
    'payment_instrument_last_four',
    'definition_hash',
    'status',
    'evidence_count',
    'dismissed_at',
    'accepted_rule_id',
])]
class LearnedRuleSuggestion extends Model
{
    /** @use HasFactory<LearnedRuleSuggestionFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'collecting',
        'evidence_count' => 0,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<LearnedRuleSuggestionEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(LearnedRuleSuggestionEvidence::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'match_mode' => LearnedRuleMatchMode::class,
            'transaction_kind' => TransactionKind::class,
            'currency' => Currency::class,
            'status' => LearnedRuleSuggestionStatus::class,
            'evidence_count' => 'integer',
            'dismissed_at' => 'immutable_datetime',
        ];
    }
}
