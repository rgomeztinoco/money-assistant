import { CurrencyFilter } from '@/components/currency-filter';
import { PeriodControls } from '@/components/period-controls';
import { reportingSelection } from '@/lib/reporting-query';
import type {
    Currency,
    ReportingPeriod,
    ReportingPeriodSelection,
} from '@/types';

export function ReportingControls({
    currencyFilter,
    period,
    today,
    href,
}: {
    currencyFilter: Currency | null;
    period: ReportingPeriod;
    today: string;
    href: (
        currencyFilter: Currency | null,
        selection: ReportingPeriodSelection,
    ) => string;
}) {
    return (
        <div
            className="flex min-w-0 flex-wrap items-center justify-end gap-x-3 gap-y-1"
            data-test="reporting-controls"
        >
            <CurrencyFilter
                value={currencyFilter}
                options={[
                    {
                        value: null,
                        label: 'All',
                        testId: 'reporting-currency-all',
                    },
                    {
                        value: 'PEN',
                        label: 'PEN',
                        testId: 'reporting-currency-pen',
                    },
                    {
                        value: 'USD',
                        label: 'USD',
                        testId: 'reporting-currency-usd',
                    },
                ]}
                href={(nextCurrencyFilter) =>
                    href(nextCurrencyFilter, reportingSelection(period))
                }
            />
            <PeriodControls
                period={period}
                today={today}
                href={(selection) => href(currencyFilter, selection)}
            />
        </div>
    );
}
