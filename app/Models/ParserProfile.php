<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ParserProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $current_version
 * @property CarbonImmutable|null $enabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'name',
    'current_version',
    'enabled_at',
])]
class ParserProfile extends Model
{
    /** @use HasFactory<ParserProfileFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'current_version' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<ParserProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ParserProfileVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'enabled_at' => 'immutable_datetime',
        ];
    }
}
