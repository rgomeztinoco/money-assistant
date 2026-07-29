<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $revision
 * @property string|null $classifier_version
 * @property string|null $taxonomy_fingerprint
 */
#[Fillable([
    'user_id',
    'revision',
    'classifier_version',
    'taxonomy_fingerprint',
])]
class AiClassificationValidationContext extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'revision' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }
}
