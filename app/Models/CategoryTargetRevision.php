<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CategoryTargetRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_target_id
 * @property int $revision
 * @property CarbonImmutable $effective_month
 * @property int|null $amount_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CategoryTarget $categoryTarget
 */
#[Fillable(['category_target_id', 'revision', 'effective_month', 'amount_minor'])]
class CategoryTargetRevision extends Model
{
    /** @use HasFactory<CategoryTargetRevisionFactory> */
    use HasFactory;

    /** @return BelongsTo<CategoryTarget, $this> */
    public function categoryTarget(): BelongsTo
    {
        return $this->belongsTo(CategoryTarget::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'effective_month' => 'immutable_date',
            'amount_minor' => 'integer',
        ];
    }
}
