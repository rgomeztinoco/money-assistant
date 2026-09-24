import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
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
    className,
}: {
    value: Currency | null;
    options: CurrencyFilterOption[];
    href: (currency: Currency | null) => string;
    className?: string;
}) {
    return (
        <div
            className={cn('flex shrink-0 items-center gap-1', className)}
            aria-label="Currency"
        >
            <span className="type-body font-medium">Currency</span>
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
        </div>
    );
}
