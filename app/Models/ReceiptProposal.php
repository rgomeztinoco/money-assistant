<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReceiptProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $proposal_id
 * @property string $source_kind
 * @property CarbonImmutable $processed_at
 * @property string $provider
 * @property string $model
 * @property int $contract_version
 * @property array{occurred_on: string, amount_minor: int, currency: string, kind: string, merchant_description: string} $proposed_transaction
 * @property list<array{description: string, line_total_minor: int}> $proposed_line_items
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'proposal_id',
    'source_kind',
    'processed_at',
    'provider',
    'model',
    'contract_version',
    'proposed_transaction',
    'proposed_line_items',
])]
final class ReceiptProposal extends Model
{
    /** @use HasFactory<ReceiptProposalFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'immutable_datetime',
            'contract_version' => 'integer',
            'proposed_transaction' => 'array',
            'proposed_line_items' => 'array',
        ];
    }
}
