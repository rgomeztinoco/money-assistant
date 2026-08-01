<?php

namespace App\Models;

use App\Concerns\HasFinancialTrash;
use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property list<string> $examples
 * @property int $revision
 * @property CarbonImmutable|null $retired_at
 * @property string|null $deletion_id
 * @property CarbonImmutable|null $purge_after
 * @property CarbonImmutable|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'parent_id',
    'name',
    'description',
    'examples',
    'revision',
    'retired_at',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasFinancialTrash;

    /** @var array<string, mixed> */
    protected $attributes = [
        'examples' => '[]',
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
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<CategoryAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CategoryAssignment::class);
    }

    /**
     * @return HasMany<CategoryAssignment, $this>
     */
    public function previousAssignments(): HasMany
    {
        return $this->hasMany(CategoryAssignment::class, 'previous_category_id');
    }

    /** @return HasMany<LineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class);
    }

    /**
     * @return HasMany<LearnedRuleRevision, $this>
     */
    public function learnedRuleRevisions(): HasMany
    {
        return $this->hasMany(LearnedRuleRevision::class);
    }

    /** @return HasMany<LearnedRuleSuggestion, $this> */
    public function learnedRuleSuggestions(): HasMany
    {
        return $this->hasMany(LearnedRuleSuggestion::class);
    }

    /** @return HasMany<LearnedRuleBulkActionItem, $this> */
    public function learnedRuleBulkActionItems(): HasMany
    {
        return $this->hasMany(LearnedRuleBulkActionItem::class, 'previous_category_id');
    }

    /** @return HasMany<AiCategoryProposal, $this> */
    public function proposedChildren(): HasMany
    {
        return $this->hasMany(AiCategoryProposal::class, 'parent_id');
    }

    /** @return HasMany<AiCategoryProposal, $this> */
    public function confirmedAiProposals(): HasMany
    {
        return $this->hasMany(AiCategoryProposal::class, 'confirmed_category_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<CategoryTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(CategoryTarget::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'examples' => 'array',
            'revision' => 'integer',
            'retired_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
