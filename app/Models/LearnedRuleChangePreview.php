<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $learned_rule_id
 * @property int|null $expected_rule_revision
 * @property int|null $source_category_assignment_id
 * @property int|null $learned_rule_suggestion_id
 * @property array<string, mixed> $definition
 * @property array<string, mixed> $analysis
 * @property string $resource_fingerprint
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $confirmed_at
 */
#[Fillable([
    'user_id',
    'learned_rule_id',
    'expected_rule_revision',
    'source_category_assignment_id',
    'learned_rule_suggestion_id',
    'definition',
    'analysis',
    'resource_fingerprint',
    'expires_at',
    'confirmed_at',
])]
class LearnedRuleChangePreview extends Model
{
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_rule_revision' => 'integer',
            'definition' => 'array',
            'analysis' => 'array',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
