<?php

namespace App\Models;

use App\Currency;
use App\TransactionKind;
use Database\Factories\MerchantRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property string $merchant
 * @property string $merchant_key
 * @property TransactionKind|null $transaction_kind
 * @property Currency|null $currency
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category $category
 */
#[Fillable([
    'category_id',
    'merchant',
    'merchant_key',
    'transaction_kind',
    'currency',
    'enabled',
])]
class MerchantRule extends Model
{
    /** @use HasFactory<MerchantRuleFactory> */
    use HasFactory, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transaction_kind' => TransactionKind::class,
            'currency' => Currency::class,
            'enabled' => 'boolean',
        ];
    }
}
