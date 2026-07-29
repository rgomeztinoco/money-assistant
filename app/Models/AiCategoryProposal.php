<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiCategoryProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property int $category_assignment_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property list<string> $examples
 * @property int $revision
 * @property int|null $confirmed_category_id
 * @property CarbonImmutable|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'category_assignment_id',
    'parent_id',
    'name',
    'description',
    'examples',
    'revision',
    'confirmed_category_id',
    'confirmed_at',
])]
class AiCategoryProposal extends Model
{
    /** @use HasFactory<AiCategoryProposalFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'examples' => '[]',
        'revision' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<CategoryAssignment, $this> */
    public function categoryAssignment(): BelongsTo
    {
        return $this->belongsTo(CategoryAssignment::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function confirmedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'confirmed_category_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'examples' => 'array',
            'revision' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
