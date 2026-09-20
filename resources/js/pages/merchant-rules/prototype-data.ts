import type { CategoryOption, MerchantRule } from '@/types';

function categoryMatching(
    categoryOptions: CategoryOption[],
    terms: string[],
    fallbackIndex: number,
): CategoryOption {
    return (
        categoryOptions.find((category) =>
            terms.some((term) =>
                category.path.toLocaleLowerCase().includes(term),
            ),
        ) ??
        categoryOptions[fallbackIndex % categoryOptions.length] ?? {
            id: fallbackIndex + 1,
            path: 'Food & Drink > Restaurants',
        }
    );
}

export function prototypeRules(
    rules: MerchantRule[],
    categoryOptions: CategoryOption[],
): { rules: MerchantRule[]; usesSamples: boolean } {
    if (rules.length > 0) {
        return { rules, usesSamples: false };
    }

    const samples = [
        {
            merchant: 'PLAZA VEA',
            terms: ['grocer', 'food'],
            kind: 'spending' as const,
            currency: 'PEN' as const,
        },
        {
            merchant: 'CAFÉ CENTRAL',
            terms: ['café', 'cafe', 'restaurant'],
            kind: 'spending' as const,
            currency: 'PEN' as const,
        },
        {
            merchant: 'UBER *TRIP',
            terms: ['ride', 'transport'],
            kind: 'spending' as const,
            currency: 'PEN' as const,
        },
        {
            merchant: 'NETFLIX.COM',
            terms: ['media', 'subscription', 'entertainment'],
            kind: 'spending' as const,
            currency: 'USD' as const,
        },
        {
            merchant: 'FARMACIA UNIVERSAL',
            terms: ['pharmacy', 'health'],
            kind: 'spending' as const,
            currency: 'PEN' as const,
        },
        {
            merchant: 'LATAM AIRLINES',
            terms: ['flight', 'travel'],
            kind: 'refund' as const,
            currency: 'USD' as const,
        },
    ];

    return {
        usesSamples: true,
        rules: samples.map((sample, index) => {
            const category = categoryMatching(
                categoryOptions,
                sample.terms,
                index,
            );

            return {
                id: -(index + 1),
                category_id: category.id,
                category_name: category.path,
                merchant: sample.merchant,
                merchant_key: sample.merchant
                    .toLocaleLowerCase()
                    .replace(/[^\p{L}\p{N}]+/gu, ' ')
                    .trim(),
                transaction_kind: sample.kind,
                currency: sample.currency,
                enabled: index !== samples.length - 1,
            };
        }),
    };
}

export function categoryPath(
    rule: MerchantRule,
    categoryOptions: CategoryOption[],
): string {
    return (
        categoryOptions.find((category) => category.id === rule.category_id)
            ?.path ?? rule.category_name
    );
}
