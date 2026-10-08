import type { Currency } from '@/types';

export type YearlyPayment = {
    id: number;
    name: string;
    amount_minor: string;
    currency: Currency;
    cushion_minor: string;
    target_minor: string;
    expected_due_on: string | null;
    is_active: boolean;
};

export type YearlyPaymentTarget = {
    currency: Currency;
    annual_target_minor: string;
    monthly_recommendation_minor: string;
};

export type YearlyPaymentPlan = {
    commitments: YearlyPayment[];
    native_targets: YearlyPaymentTarget[];
    combined_estimate: {
        status: 'available' | 'unavailable';
        currency: Currency;
        annual_target_minor: string | null;
        monthly_recommendation_minor: string | null;
        unavailable_reason: string | null;
    };
    planning_rate: {
        pen_per_usd: string | null;
        direction: string;
        source: string;
    };
    upcoming_commitments: YearlyPayment[];
    calculation_date: string;
    timezone: string;
    assumptions: {
        target_scope: string;
        monthly_rounding: string;
        conversion_rounding: string;
        due_dates: string;
        planning_only: string;
    };
};
