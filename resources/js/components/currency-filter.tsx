import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Currency } from '@/types';

type CurrencyFilterOption = {
    value: Currency | null;
    label: string;
    testId?: string;
};

export function CurrencyFilter({
    value,
    options,
    href,
}: {
    value: Currency | null;
    options: CurrencyFilterOption[];
    href: (currency: Currency | null) => string;
}) {
    return (
        <>
            <span className="text-sm font-medium">Currency</span>
            {options.map((option) => (
                <Button
                    key={option.label}
                    asChild
                    size="sm"
                    variant={value === option.value ? 'secondary' : 'ghost'}
                >
                    <Link
                        href={href(option.value)}
                        preserveScroll
                        data-test={option.testId}
                    >
                        {option.label}
                    </Link>
                </Button>
            ))}
        </>
    );
}
