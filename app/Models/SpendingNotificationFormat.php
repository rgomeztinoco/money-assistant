<?php

namespace App\Models;

use App\SpendingNotificationFormatPurpose;
use Database\Factories\SpendingNotificationFormatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $parser_profile_version_id
 * @property string $name
 * @property string $mime_source
 * @property string $rule_identifier
 * @property SpendingNotificationFormatPurpose $purpose
 * @property array<string, mixed> $definition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'parser_profile_version_id',
    'name',
    'mime_source',
    'rule_identifier',
    'purpose',
    'definition',
])]
class SpendingNotificationFormat extends Model
{
    /** @use HasFactory<SpendingNotificationFormatFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'purpose' => SpendingNotificationFormatPurpose::Spending->value,
    ];

    /** @return BelongsTo<ParserProfileVersion, $this> */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(ParserProfileVersion::class, 'parser_profile_version_id');
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
            'definition' => 'array',
            'purpose' => SpendingNotificationFormatPurpose::class,
        ];
    }
}
