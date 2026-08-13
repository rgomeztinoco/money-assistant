<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\ReadRecentTransactions;
use App\Actions\Ledger\CountOutstandingReviews;
use App\Actions\NotificationIngestion\ReadGmailConnectionStatus;
use App\Actions\Reporting\ReadCurrencyTotals;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private ReadCurrencyTotals $readCurrencyTotals,
        private ReadRecentTransactions $readRecentTransactions,
        private CountOutstandingReviews $countOutstandingReviews,
        private ReadGmailConnectionStatus $readGmailConnectionStatus,
    ) {}

    public function __invoke(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $gmailStatus = $this->readGmailConnectionStatus->handle($request->user());

        return Inertia::render('dashboard', [
            'period' => [
                'label' => $today->isoFormat('MMMM YYYY'),
                'date_from' => $monthStart->toDateString(),
                'date_to' => $today->toDateString(),
            ],
            'spending' => [
                'totals' => $this->readCurrencyTotals->handle(
                    owner: $request->user(),
                    dateFrom: $monthStart,
                    dateTo: $today,
                ),
            ],
            'review_queue' => [
                'outstanding_count' => $this->countOutstandingReviews->handle($request->user()),
            ],
            'recent_transactions' => $this->readRecentTransactions->handle($request->user()),
            'gmail' => [
                'state' => $gmailStatus['state'],
                'account_identity' => $gmailStatus['account_identity'],
                'last_successful_sync_at' => $gmailStatus['last_successful_sync_at'],
            ],
        ]);
    }
}
