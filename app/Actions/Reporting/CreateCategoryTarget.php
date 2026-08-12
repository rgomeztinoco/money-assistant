<?php

namespace App\Actions\Reporting;

use App\Currency;
use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateCategoryTarget
{
    public function __construct(private ReadSpendingInsights $readSpendingInsights) {}

    public function handle(
        User $owner,
        int $categoryId,
        string $amountMinor,
        Currency $currency,
        CarbonImmutable $startsOn,
    ): CategoryTarget {
        try {
            return DB::transaction(function () use ($owner, $categoryId, $amountMinor, $currency, $startsOn): CategoryTarget {
                User::query()->whereKey($owner->id)->lockForUpdate()->sole();

                $insights = $this->readSpendingInsights->handle($owner, CarbonImmutable::today()->startOfMonth());

                if ($insights['baseline']['status'] !== 'established') {
                    throw ValidationException::withMessages([
                        'category_id' => 'Three complete reviewed months are required before a Category Target can be created.',
                    ]);
                }

                $category = Category::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->whereKey($categoryId)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->firstOrFail();

                $target = CategoryTarget::query()->create([
                    'user_id' => $owner->id,
                    'category_id' => $category->id,
                    'currency' => $currency,
                    'starts_on' => $startsOn->startOfMonth(),
                ]);
                $target->revisions()->create([
                    'revision' => 1,
                    'effective_month' => $startsOn->startOfMonth(),
                    'amount_minor' => $amountMinor,
                ]);

                return $target;
            }, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'category_id' => 'This Category already has a Target.',
            ]);
        }
    }
}
