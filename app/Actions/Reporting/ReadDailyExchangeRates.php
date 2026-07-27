<?php

namespace App\Actions\Reporting;

use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\User;

final class ReadDailyExchangeRates
{
    /**
     * @return array{
     *     rates: list<array{id: int, applicable_on: string, pen_per_usd: string, pen_per_usd_scaled: string, revision: int, owner_managed: bool, source: array{label: string, attribution: string, series: string, observed_on: string, retrieved_at: string, value: string, precision: int}|null}>,
     *     seed_requests: list<array{id: int, applicable_on: string, state: 'pending'|'owner_entry_required'|'retrieval_failed', attempt_count: int, next_attempt_at: string|null}>,
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
                'source' => $rate->source === 'bcrp_data' ? [
                    'label' => 'BCRP interbank sell',
                    'attribution' => 'Banco Central de Reserva del Peru',
                    'series' => $rate->source_series,
                    'observed_on' => $rate->source_observed_on?->toDateString(),
                    'retrieved_at' => $rate->source_retrieved_at?->toIso8601String(),
                    'value' => $rate->source_value,
                    'precision' => $rate->source_precision,
                ] : null,
            ];
        }

        $seedRequests = [];

        foreach (DailyExchangeRateSeedRequest::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('completed_at')
            ->orderByDesc('owner_entry_required_at')
            ->orderByDesc('applicable_on')
            ->limit(100)
            ->get() as $seedRequest) {
            $seedRequests[] = [
                'id' => $seedRequest->id,
                'applicable_on' => $seedRequest->applicable_on->toDateString(),
                'state' => match (true) {
                    $seedRequest->owner_entry_required_at !== null => 'owner_entry_required',
                    $seedRequest->retrieval_failed_at !== null => 'retrieval_failed',
                    default => 'pending',
                },
                'attempt_count' => $seedRequest->attempt_count,
                'next_attempt_at' => $seedRequest->next_attempt_at?->toIso8601String(),
            ];
        }

        return [
            'rates' => $rates,
            'seed_requests' => $seedRequests,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'previous_page' => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
                'next_page' => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            ],
        ];
    }
}
