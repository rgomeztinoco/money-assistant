<?php

namespace App\Actions\Reporting;

use App\ExactInteger;
use Carbon\CarbonImmutable;

final readonly class EquivalentMonthPeriods
{
    private const int PreviousPeriodCount = 3;

    /** @param non-empty-list<array{CarbonImmutable, CarbonImmutable}> $periods */
    private function __construct(private array $periods) {}

    public static function forRange(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): self
    {
        $elapsedDays = (int) $dateFrom->diffInDays($dateTo);
        $periods = [[$dateFrom, $dateTo]];

        foreach (range(1, self::PreviousPeriodCount) as $offset) {
            $previousDateFrom = $dateFrom->subMonthsNoOverflow($offset)->startOfMonth();
            $periods[] = [
                $previousDateFrom,
                $previousDateFrom->addDays($elapsedDays)->min($previousDateFrom->endOfMonth()),
            ];
        }

        return new self($periods);
    }

    /** @return non-empty-list<array{CarbonImmutable, CarbonImmutable}> */
    public function all(): array
    {
        return $this->periods;
    }

    /** @return list<array{CarbonImmutable, CarbonImmutable}> */
    public function comparisons(): array
    {
        return array_slice($this->periods, 1);
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
}
