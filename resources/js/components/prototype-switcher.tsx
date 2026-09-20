import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, FlaskConical } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';

export type PrototypeVariant = 'A' | 'B' | 'C';

const variants: PrototypeVariant[] = ['A', 'B', 'C'];

export function readPrototypeVariant(url: string): PrototypeVariant | null {
    if (
        !import.meta.env.DEV &&
        import.meta.env.VITE_ENABLE_PROTOTYPES !== 'true'
    ) {
        return null;
    }

    const variant = new URL(url, 'http://prototype.local').searchParams.get(
        'variant',
    );

    return variants.includes(variant as PrototypeVariant)
        ? (variant as PrototypeVariant)
        : 'A';
}

export function PrototypeSwitcher({
    current,
    labels,
}: {
    current: PrototypeVariant;
    labels: Record<PrototypeVariant, string>;
}) {
    const { url } = usePage();

    function visitVariant(nextVariant: PrototypeVariant) {
        const nextUrl = new URL(url, 'http://prototype.local');
        nextUrl.searchParams.set('variant', nextVariant);

        router.visit(`${nextUrl.pathname}${nextUrl.search}${nextUrl.hash}`, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function cycle(direction: -1 | 1) {
        const currentIndex = variants.indexOf(current);
        const nextIndex =
            (currentIndex + direction + variants.length) % variants.length;

        visitVariant(variants[nextIndex]);
    }

    useEffect(() => {
        function handleKeyDown(event: KeyboardEvent) {
            const target = event.target as HTMLElement | null;

            if (
                target?.matches('input, textarea, select') ||
                target?.isContentEditable
            ) {
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                cycle(-1);
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                cycle(1);
            }
        }

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    });

    return (
        <div className="fixed right-1/2 bottom-4 z-50 flex translate-x-1/2 items-center gap-2 rounded-full border bg-background p-2 shadow-lg">
            <Button
                type="button"
                variant="secondary"
                size="icon"
                aria-label="Previous prototype"
                onClick={() => cycle(-1)}
            >
                <ArrowLeft />
            </Button>
            <div className="flex min-w-48 items-center justify-center gap-2 px-2 text-sm font-medium">
                <FlaskConical className="size-4 text-muted-foreground" />
                <span>
                    {current} · {labels[current]}
                </span>
            </div>
            <Button
                type="button"
                variant="secondary"
                size="icon"
                aria-label="Next prototype"
                onClick={() => cycle(1)}
            >
                <ArrowRight />
            </Button>
        </div>
    );
}
