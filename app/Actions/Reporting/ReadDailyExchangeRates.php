<?php

namespace App\Actions\Reporting;

use App\Models\DailyExchangeRate;
use App\Models\User;

final class ReadDailyExchangeRates
{
    /**
     * @return array{
     *     rates: list<array{id: int, applicable_on: string, pen_per_usd: string, pen_per_usd_scaled: string, revision: int, owner_managed: bool}>,
     *     pagination: array{current_page: int, last_page: int, previous_page: int|null, next_page: int|null}
     * }
     */
    public function handle(User $owner): array
    {
        $paginator = DailyExchangeRate::query()
            ->whereBelongsTo($owner, 'owner')
            ->orderByDesc('applicable_on')
            ->orderByDesc('id')
            ->paginate(50, pageName: 'rates_page');
        $rates = [];

        foreach ($paginator->getCollection() as $rate) {
            $rates[] = [
                'id' => $rate->id,
                'applicable_on' => $rate->applicable_on->toDateString(),
                'pen_per_usd' => $rate->penPerUsd(),
                'pen_per_usd_scaled' => (string) $rate->pen_per_usd_scaled,
                'revision' => $rate->revision,
                'owner_managed' => $rate->owner_managed_at !== null,
            ];
        }

        return [
            'rates' => $rates,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'previous_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            ],
        ];
    }
}
