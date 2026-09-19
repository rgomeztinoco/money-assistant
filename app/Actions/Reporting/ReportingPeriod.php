<?php

namespace App\Actions\Reporting;

use Carbon\CarbonImmutable;

final readonly class ReportingPeriod
{
    private function __construct(
        public string $unit,
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
    ) {}

    /**
     * @param  array{period?: string, anchor?: string, preset?: string, date_from?: string, date_to?: string}  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $periodUnit = $filters['period'] ?? null;
        $anchor = CarbonImmutable::parse($filters['anchor'] ?? $today, config('app.timezone'));
        $preset = $filters['preset'] ?? null;

        if (($periodUnit === 'custom' || $preset === 'custom')
            && isset($filters['date_from'], $filters['date_to'])) {
            return new self(
                'custom',
                CarbonImmutable::parse($filters['date_from'], config('app.timezone')),
                CarbonImmutable::parse($filters['date_to'], config('app.timezone')),
            );
        }

        if ($periodUnit === 'week') {
            return new self('week', $anchor->startOfWeek(), $anchor->endOfWeek());
        }

        if ($periodUnit === 'quarter') {
            return new self('quarter', $anchor->startOfQuarter(), $anchor->endOfQuarter());
        }

        if ($periodUnit === 'year') {
            return new self('year', $anchor->startOfYear(), $anchor->endOfYear());
        }

        if ($periodUnit === 'month') {
            return new self('month', $anchor->startOfMonth(), $anchor->endOfMonth());
        }

        if ($preset === 'last_month') {
            $month = $today->subMonthNoOverflow();

            return new self('month', $month->startOfMonth(), $month->endOfMonth());
        }

        if ($preset === 'rolling_30') {
            return new self('custom', $today->subDays(29), $today);
        }

        return new self('month', $today->startOfMonth(), $today->endOfMonth());
    }

    public function elapsedThrough(CarbonImmutable $cutoff): ?self
    {
        if ($this->dateFrom->greaterThan($cutoff)) {
            return null;
        }

        if (! $this->dateTo->greaterThan($cutoff)) {
            return $this;
        }

        return new self($this->unit, $this->dateFrom, $cutoff);
    }

    /** @return array{unit: string, anchor: string, date_from: string, date_to: string} */
    public function data(): array
    {
        return [
            'unit' => $this->unit,
            'anchor' => $this->anchor(),
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
        ];
    }

    private function anchor(): string
    {
        return match ($this->unit) {
            'week' => $this->dateFrom->startOfWeek()->toDateString(),
            'month' => $this->dateFrom->startOfMonth()->toDateString(),
            'quarter' => $this->dateFrom->startOfQuarter()->toDateString(),
            'year' => $this->dateFrom->startOfYear()->toDateString(),
            default => $this->dateFrom->toDateString(),
        };
    }
}
