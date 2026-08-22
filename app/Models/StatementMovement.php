<?php

namespace App\Models;

use App\Currency;
use App\MovementDirection;
use App\StatementMovementClassification;
use Carbon\CarbonImmutable;
use Database\Factories\StatementMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $statement_import_id
 * @property int $transaction_id
 * @property string $source_row_id
 * @property int $position
 * @property CarbonImmutable $occurred_on
 * @property int $amount_minor
 * @property Currency $currency
 * @property MovementDirection $direction
 * @property StatementMovementClassification $classification
 * @property string $description
 * @property array<string, mixed> $source_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'statement_import_id',
    'transaction_id',
    'source_row_id',
    'position',
    'occurred_on',
    'amount_minor',
    'currency',
    'direction',
    'classification',
    'description',
    'source_metadata',
])]
class StatementMovement extends Model
{
    /** @use HasFactory<StatementMovementFactory> */
    use HasFactory;

    /** @return BelongsTo<StatementImport, $this> */
    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'occurred_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'direction' => MovementDirection::class,
            'classification' => StatementMovementClassification::class,
            'source_metadata' => 'array',
        ];
    }
}
