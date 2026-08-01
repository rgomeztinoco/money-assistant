<?php

namespace App\Actions\Reporting;

use App\Models\CategoryTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviseCategoryTarget
{
    public function handle(
        User $owner,
        int $targetId,
        ?string $amountMinor,
        CarbonImmutable $effectiveMonth,
        int $expectedRevision,
    ): CategoryTarget {
        return DB::transaction(function () use ($owner, $targetId, $amountMinor, $effectiveMonth, $expectedRevision): CategoryTarget {
            $target = CategoryTarget::query()
                ->whereBelongsTo($owner, 'owner')
                ->whereKey($targetId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($target->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This Category Target changed. Review its latest revision and try again.',
                ]);
            }

            $effectiveMonth = $effectiveMonth->startOfMonth();

            if ($effectiveMonth->lessThan(CarbonImmutable::today()->startOfMonth())) {
                throw ValidationException::withMessages([
                    'effective_month' => 'A Category Target can change only for the current or a future month.',
                ]);
            }

            if ($effectiveMonth->lessThan($target->starts_on)) {
                throw ValidationException::withMessages([
                    'effective_month' => 'A Category Target cannot change before its starting month.',
                ]);
            }

            $nextRevision = $target->revision + 1;
            $target->revisions()->create([
                'revision' => $nextRevision,
                'effective_month' => $effectiveMonth,
                'amount_minor' => $amountMinor,
            ]);
            $target->revision = $nextRevision;
            $target->save();

            return $target;
        }, 3);
    }
}
