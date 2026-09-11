import type {
    Currency,
    ReportingPeriod,
    ReportingPeriodSelection,
} from '@/types';

export function reportingSelection(
    period: ReportingPeriod,
): ReportingPeriodSelection {
    return period.unit === 'custom'
        ? {
              unit: 'custom',
              dateFrom: period.date_from,
              dateTo: period.date_to,
          }
        : { unit: period.unit, anchor: period.anchor };
}

export function reportingQuery(
    currencyFilter: Currency | null,
    selection: ReportingPeriodSelection,
) {
    return {
        currency: currencyFilter ?? undefined,
        period: selection.unit,
        anchor: selection.unit === 'custom' ? undefined : selection.anchor,
        date_from: selection.unit === 'custom' ? selection.dateFrom : undefined,
        date_to: selection.unit === 'custom' ? selection.dateTo : undefined,
    };
}

export function reportingQueryFromUrl(url: string) {
    const parameters = new URL(url, 'http://localhost').searchParams;

    return {
        currency: parameters.get('currency') ?? undefined,
        period: parameters.get('period') ?? undefined,
        anchor: parameters.get('anchor') ?? undefined,
        preset: parameters.get('preset') ?? undefined,
        date_from: parameters.get('date_from') ?? undefined,
        date_to: parameters.get('date_to') ?? undefined,
    };
}
