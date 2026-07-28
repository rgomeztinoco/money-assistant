<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReceiptBreakdownFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property int|null $receipt_proposal_id
 * @property string $status
 * @property int $revision
 * @property CarbonImmutable|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'transaction_id',
    'receipt_proposal_id',
    'status',
    'revision',
    'confirmed_at',
])]
final class ReceiptBreakdown extends Model
{
    /** @use HasFactory<ReceiptBreakdownFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'confirmed',
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
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<ReceiptProposal, $this> */
    public function receiptProposal(): BelongsTo
    {
        return $this->belongsTo(ReceiptProposal::class);
    }

    /** @return HasMany<LineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(LineItem::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
