<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ParserProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $trusted_sender_address
 * @property string $trusted_sender_domain
 * @property string $authentication_mechanism
 * @property string $authenticated_domain
 * @property CarbonImmutable|null $enabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'trusted_sender_address',
    'trusted_sender_domain',
    'authentication_mechanism',
    'authenticated_domain',
    'enabled_at',
])]
class ParserProfile extends Model
{
    /** @use HasFactory<ParserProfileFactory> */
    use HasFactory;

    /** @return HasMany<SpendingNotificationFormat, $this> */
    public function formats(): HasMany
    {
        return $this->hasMany(SpendingNotificationFormat::class);
    }

    /** @return HasMany<SpendingNotificationFormat, $this> */
    public function spendingNotificationFormats(): HasMany
    {
        return $this->formats();
    }

    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled_at' => 'immutable_datetime',
        ];
    }
}
