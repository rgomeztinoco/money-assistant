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
 * @property CarbonImmutable|null $processing_failed_at
 * @property string|null $last_error_code
 * @property string|null $failed_job_uuid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GmailConnection $gmailConnection
 */
#[Fillable([
    'gmail_connection_id',
    'message_id',
    'processed_at',
    'processing_failed_at',
    'last_error_code',
    'failed_job_uuid',
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
            'processing_failed_at' => 'immutable_datetime',
        ];
    }
}
