<?php

namespace App\Models;

use Database\Factories\LineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $line_item_id
 * @property int $receipt_breakdown_id
 * @property int|null $category_id
 * @property string $description
 * @property string $role
 * @property int $line_total_minor
 * @property bool $requires_review
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'line_item_id',
    'receipt_breakdown_id',
    'category_id',
    'description',
    'role',
    'line_total_minor',
    'requires_review',
])]
final class LineItem extends Model
{
    /** @use HasFactory<LineItemFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'purchased_item',
        'requires_review' => false,
    ];

    /** @return BelongsTo<ReceiptBreakdown, $this> */
    public function receiptBreakdown(): BelongsTo
    {
        return $this->belongsTo(ReceiptBreakdown::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'line_total_minor' => 'integer',
            'requires_review' => 'boolean',
        ];
    }
}
