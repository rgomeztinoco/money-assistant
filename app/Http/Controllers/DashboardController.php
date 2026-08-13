<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\ReadOperatingStatus;
use App\Actions\Reporting\ReadCurrencyTotals;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private ReadCurrencyTotals $readCurrencyTotals,
        private ReadOperatingStatus $readOperatingStatus,
    ) {}

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        return Inertia::render('dashboard', [
            'period' => [
                'label' => $today->isoFormat('MMMM YYYY'),
                'date_from' => $monthStart->toDateString(),
                'date_to' => $today->toDateString(),
            ],
            'spending' => [
                'totals' => $this->readCurrencyTotals->handle(
                    dateFrom: $monthStart,
                    dateTo: $today,
                ),
            ],
            'operating' => $this->readOperatingStatus->handle(),
        ]);
    }
}
