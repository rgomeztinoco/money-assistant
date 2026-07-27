<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $learned_rule_id
 * @property int $learned_rule_revision
 * @property string $rules_fingerprint
 * @property string $status
 * @property CarbonImmutable $preview_expires_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $undone_at
 * @property-read int $transaction_count
 * @property-read int $restored_count
 * @property-read int $skipped_count
 */
#[Fillable([
    'user_id',
    'learned_rule_id',
    'learned_rule_revision',
    'rules_fingerprint',
    'status',
    'preview_expires_at',
    'applied_at',
    'undone_at',
])]
class LearnedRuleBulkAction extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'previewed'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<LearnedRule, $this> */
    public function learnedRule(): BelongsTo
    {
        return $this->belongsTo(LearnedRule::class);
    }

    /** @return HasMany<LearnedRuleBulkActionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LearnedRuleBulkActionItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'learned_rule_revision' => 'integer',
            'preview_expires_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
        ];
    }
}
