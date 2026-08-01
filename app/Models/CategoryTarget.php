<?php

namespace App\Models;

use App\Currency;
use Carbon\CarbonImmutable;
use Database\Factories\CategoryTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property int $category_id
 * @property Currency $currency
 * @property CarbonImmutable $starts_on
 * @property int $revision
 * @property int|null $applicable_revision_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $owner
 * @property-read Category $category
 * @property-read Collection<int, CategoryTargetRevision> $revisions
 * @property-read CategoryTargetRevision|null $currentRevision
 */
#[Fillable(['user_id', 'category_id', 'currency', 'starts_on', 'revision'])]
class CategoryTarget extends Model
{
    /** @use HasFactory<CategoryTargetFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['revision' => 1];

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

    /** @return HasMany<CategoryTargetRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(CategoryTargetRevision::class);
    }

    /** @return HasOne<CategoryTargetRevision, $this> */
    public function currentRevision(): HasOne
    {
        return $this->hasOne(CategoryTargetRevision::class)->ofMany('revision', 'max');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'starts_on' => 'immutable_date',
            'revision' => 'integer',
            'applicable_revision_id' => 'integer',
        ];
    }
}
