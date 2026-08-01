<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $owner_id
 * @property string $resource_type
 * @property int $resource_id
 * @property string|null $source_reference_type
 * @property int|null $source_reference_id
 * @property CarbonImmutable $deleted_at
 * @property CarbonImmutable $purged_at
 */
#[Fillable([
    'id',
    'owner_id',
    'resource_type',
    'resource_id',
    'source_reference_type',
    'source_reference_id',
    'deleted_at',
    'purged_at',
])]
class FinancialDataTombstone extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'resource_id' => 'integer',
            'source_reference_id' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
