<?php

namespace App\Actions\Reporting;

use App\ExactInteger;
use Carbon\CarbonImmutable;

final readonly class EquivalentPeriods
{
    private const int PreviousPeriodCount = 3;

    /** @param non-empty-list<array{CarbonImmutable, CarbonImmutable}> $periods */
    private function __construct(private array $periods) {}

    public static function forPeriod(ReportingPeriod $period): self
    {
        $periods = [[$period->dateFrom, $period->dateTo]];
        $durationDays = (int) $period->dateFrom->diffInDays($period->dateTo) + 1;

        foreach (range(1, self::PreviousPeriodCount) as $offset) {
            $periods[] = self::previousPeriod($period, $durationDays, $offset);
        }

        return new self($periods);
    }

    /** @return non-empty-list<array{CarbonImmutable, CarbonImmutable}> */
    public function all(): array
    {
        return $this->periods;
    }

    /** @return list<int> */
    public function comparisonIndexes(): array
    {
        return range(1, self::PreviousPeriodCount);
    }

    public function comparisonCount(): int
    {
        return self::PreviousPeriodCount;
    }

    public function indexOf(CarbonImmutable $occurredOn): ?int
    {
        foreach ($this->periods as $index => [$dateFrom, $dateTo]) {
            if ($occurredOn->betweenIncluded($dateFrom, $dateTo)) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<int, ExactInteger> $amounts */
    public function typicalAmount(array $amounts): ExactInteger
    {
        $total = ExactInteger::from(0);

        foreach ($this->comparisonIndexes() as $index) {
            $total = $total->add($amounts[$index] ?? ExactInteger::from(0));
        }

        return ExactInteger::from(bcdiv($total->value(), (string) self::PreviousPeriodCount, 0));
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function previousPeriod(
        ReportingPeriod $period,
        int $durationDays,
        int $offset,
    ): array {
        return match ($period->unit) {
            'week' => [
                $period->dateFrom->subWeeks($offset),
                $period->dateTo->subWeeks($offset),
            ],
            'month' => self::previousCalendarPeriod(
                $period,
                $period->dateFrom->subMonthsNoOverflow($offset)->startOfMonth(),
                $durationDays,
                'month',
            ),
            'quarter' => self::previousCalendarPeriod(
                $period,
                $period->dateFrom->subMonthsNoOverflow($offset * 3)->startOfQuarter(),
                $durationDays,
                'quarter',
            ),
            'year' => self::previousCalendarPeriod(
                $period,
                $period->dateFrom->subYearsNoOverflow($offset)->startOfYear(),
                $durationDays,
                'year',
            ),
            default => [
                $period->dateFrom->subDays($durationDays * $offset),
                $period->dateTo->subDays($durationDays * $offset),
            ],
        };
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function previousCalendarPeriod(
        ReportingPeriod $period,
        CarbonImmutable $dateFrom,
        int $durationDays,
        string $unit,
    ): array {
        $periodEnd = match ($unit) {
            'month' => $dateFrom->endOfMonth(),
            'quarter' => $dateFrom->endOfQuarter(),
            default => $dateFrom->endOfYear(),
        };
        $currentPeriodEnd = match ($unit) {
            'month' => $period->dateTo->endOfMonth(),
            'quarter' => $period->dateTo->endOfQuarter(),
            default => $period->dateTo->endOfYear(),
        };
        $dateTo = $period->dateTo->equalTo($currentPeriodEnd)
            ? $periodEnd
            : $dateFrom->addDays($durationDays - 1)->min($periodEnd);

        return [$dateFrom, $dateTo];
    }
}
