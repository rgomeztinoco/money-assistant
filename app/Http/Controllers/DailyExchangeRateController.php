<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\ReadDailyExchangeRates;
use App\Actions\Reporting\RetryDailyExchangeRateSeed;
use App\Actions\Reporting\SetDailyExchangeRate;
use App\Exceptions\StaleDailyExchangeRateRevision;
use App\Http\Requests\StoreDailyExchangeRateRequest;
use App\Http\Requests\UpdateDailyExchangeRateRequest;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyExchangeRateController extends Controller
{
    public function __construct(
        private ReadDailyExchangeRates $readDailyExchangeRates,
        private RetryDailyExchangeRateSeed $retryDailyExchangeRateSeed,
        private SetDailyExchangeRate $setDailyExchangeRate,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('daily-exchange-rates/index', [
            'reporting_currency' => $request->user()->reporting_currency?->value,
            ...$this->readDailyExchangeRates->handle($request->user()),
        ]);
    }

    public function store(StoreDailyExchangeRateRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $this->setDailyExchangeRate->handle(
            owner: $request->user(),
            applicableOn: CarbonImmutable::parse($validated['applicable_on'], config('app.timezone')),
            penPerUsd: $validated['pen_per_usd'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Daily Exchange Rate created.')]);

        return to_route('daily_exchange_rates.index');
    }

    public function update(UpdateDailyExchangeRateRequest $request, DailyExchangeRate $dailyExchangeRate): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->setDailyExchangeRate->handle(
                owner: $request->user(),
                applicableOn: $dailyExchangeRate->applicable_on,
                penPerUsd: $validated['pen_per_usd'],
                rateId: $dailyExchangeRate->id,
                expectedRevision: (int) $validated['expected_revision'],
            );
        } catch (StaleDailyExchangeRateRevision $exception) {
            return back()->withErrors(['expected_revision' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Daily Exchange Rate updated.')]);

        return to_route('daily_exchange_rates.index');
    }

    public function retrySeed(Request $request, DailyExchangeRateSeedRequest $dailyExchangeRateSeedRequest): RedirectResponse
    {
        $this->retryDailyExchangeRateSeed->handle(
            owner: $request->user(),
            seedRequestId: $dailyExchangeRateSeedRequest->id,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('BCRP retrieval queued for retry.')]);

        return to_route('daily_exchange_rates.index');
    }
}
