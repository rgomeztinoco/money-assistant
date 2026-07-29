<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GmailMessageDiscoveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $gmail_connection_id
 * @property string $message_id
 * @property CarbonImmutable|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'gmail_connection_id',
    'message_id',
    'processed_at',
])]
class GmailMessageDiscovery extends Model
{
    /** @use HasFactory<GmailMessageDiscoveryFactory> */
    use HasFactory;

    /** @return BelongsTo<GmailConnection, $this> */
    public function gmailConnection(): BelongsTo
    {
        return $this->belongsTo(GmailConnection::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'processed_at' => 'immutable_datetime',
        ];
    }
}
