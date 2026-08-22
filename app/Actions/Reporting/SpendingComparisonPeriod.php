<?php

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

final readonly class SpendingComparisonPeriod
{
    private function __construct(
        public CarbonImmutable $currentDateFrom,
        public CarbonImmutable $currentDateTo,
        public CarbonImmutable $previousDateFrom,
        public CarbonImmutable $previousDateTo,
    ) {}

    public static function monthToDate(CarbonImmutable $today): self
    {
        $currentDateFrom = $today->startOfMonth();
        $previousDateFrom = $currentDateFrom->subMonthNoOverflow()->startOfMonth();
        $previousDateTo = $previousDateFrom
            ->addDays($currentDateFrom->diffInDays($today))
            ->min($previousDateFrom->endOfMonth());

        return new self($currentDateFrom, $today, $previousDateFrom, $previousDateTo);
    }

    public static function preceding(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): self
    {
        $previousDateTo = $dateFrom->subDay();
        $previousDateFrom = $previousDateTo->subDays((int) $dateFrom->diffInDays($dateTo));

        return new self($dateFrom, $dateTo, $previousDateFrom, $previousDateTo);
    }
}
