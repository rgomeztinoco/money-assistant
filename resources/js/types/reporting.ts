export type ReportingPeriodUnit =
    'week' | 'month' | 'quarter' | 'year' | 'custom';

export type ReportingPeriod = {
    unit: ReportingPeriodUnit;
    label: string;
    anchor: string;
    date_from: string;
    date_to: string;
};

export type ReportingPeriodSelection =
    | {
          unit: Exclude<ReportingPeriodUnit, 'custom'>;
          anchor: string;
      }
    | {
          unit: 'custom';
          dateFrom: string;
          dateTo: string;
      };
