<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** @mixin Model */
trait HasFinancialTrash
{
    use SoftDeletes;

    public const int TRASH_RETENTION_DAYS = 30;

    public function moveToFinancialTrash(): void
    {
        $this->forceFill([
            'deletion_id' => Str::uuid()->toString(),
            'purge_after' => now()->addDays(self::TRASH_RETENTION_DAYS),
        ])->save();
        $this->delete();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiredTrash(Builder $query): Builder
    {
        $query->onlyTrashed()
            ->whereNotNull('deletion_id')
            ->where('purge_after', '<=', now());

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRestorableTrash(Builder $query): Builder
    {
        $query->onlyTrashed()
            ->whereNotNull('deletion_id')
            ->where('purge_after', '>', now());

        return $query;
    }
}
