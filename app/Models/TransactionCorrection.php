<?php

namespace App\Models;

use App\ReviewableTransactionField;
use Database\Factories\TransactionCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $transaction_id
 * @property ReviewableTransactionField $field
 * @property string $previous_value
 * @property string $corrected_value
 * @property int $transaction_revision
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'field',
    'previous_value',
    'corrected_value',
    'transaction_revision',
])]
class TransactionCorrection extends Model
{
    /** @use HasFactory<TransactionCorrectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field' => ReviewableTransactionField::class,
            'transaction_revision' => 'integer',
        ];
    }
}
