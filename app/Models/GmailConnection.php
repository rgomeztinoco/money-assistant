<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GmailConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $gmail_account_identity
 * @property string $access_token
 * @property string $refresh_token
 * @property CarbonImmutable $access_token_expires_at
 * @property list<string> $granted_scopes
 * @property CarbonImmutable $connected_at
 * @property CarbonImmutable $last_successful_check_at
 * @property CarbonImmutable|null $last_check_failed_at
 * @property CarbonImmutable|null $reauthorization_required_at
 * @property string|null $last_error_code
 * @property string|null $history_id
 * @property CarbonImmutable|null $initial_sync_completed_at
 * @property CarbonImmutable|null $last_successful_sync_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'gmail_account_identity',
    'access_token',
    'refresh_token',
    'access_token_expires_at',
    'granted_scopes',
    'connected_at',
    'last_successful_check_at',
    'last_check_failed_at',
    'reauthorization_required_at',
    'last_error_code',
    'history_id',
    'initial_sync_completed_at',
    'last_successful_sync_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GmailConnection extends Model
{
    public const ERROR_CHECK_FAILED = 'gmail_check_failed';

    public const ERROR_GMAIL_ACCOUNT_MISMATCH = 'gmail_account_mismatch';

    public const ERROR_REFRESH_TOKEN_REJECTED = 'refresh_token_rejected';

    /** @use HasFactory<GmailConnectionFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ingestionIsPaused(): bool
    {
        return $this->reauthorization_required_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'immutable_datetime',
            'granted_scopes' => 'array',
            'connected_at' => 'immutable_datetime',
            'last_successful_check_at' => 'immutable_datetime',
            'last_check_failed_at' => 'immutable_datetime',
            'reauthorization_required_at' => 'immutable_datetime',
            'initial_sync_completed_at' => 'immutable_datetime',
            'last_successful_sync_at' => 'immutable_datetime',
        ];
    }
}
