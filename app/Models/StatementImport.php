<?php

namespace App\Models;

use App\FinancialStatementFormat;
use Carbon\CarbonImmutable;
use Database\Factories\StatementImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property FinancialStatementFormat $financial_statement_format
 * @property string $parser_version
 * @property string $file_hash
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $instrument_label
 * @property string|null $instrument_last_four
 * @property array<string, string> $reconciliation_values
 * @property-read int $movements_count
 * @property CarbonImmutable $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'financial_statement_format',
    'parser_version',
    'file_hash',
    'period_start',
    'period_end',
    'instrument_label',
    'instrument_last_four',
    'reconciliation_values',
    'confirmed_at',
])]
class StatementImport extends Model
{
    /** @use HasFactory<StatementImportFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<StatementMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StatementMovement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'financial_statement_format' => FinancialStatementFormat::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'reconciliation_values' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
