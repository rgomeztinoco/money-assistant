<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ParserProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $parser_profile_id
 * @property int $version
 * @property string $trusted_sender_address
 * @property string $trusted_sender_domain
 * @property string $authentication_mechanism
 * @property string $authenticated_domain
 * @property string $source_gmail_account_identity
 * @property string $source_message_id
 * @property CarbonImmutable $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parser_profile_id',
    'version',
    'trusted_sender_address',
    'trusted_sender_domain',
    'authentication_mechanism',
    'authenticated_domain',
    'source_gmail_account_identity',
    'source_message_id',
    'approved_at',
])]
class ParserProfileVersion extends Model
{
    /** @use HasFactory<ParserProfileVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<ParserProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ParserProfile::class, 'parser_profile_id');
    }

    /** @return HasMany<SpendingNotificationFormat, $this> */
    public function formats(): HasMany
    {
        return $this->hasMany(SpendingNotificationFormat::class);
    }

    /** @return HasMany<SpendingNotificationReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(SpendingNotificationReference::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
