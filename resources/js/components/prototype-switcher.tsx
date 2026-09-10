// Throwaway UI comparison controls. Remove when the design explorations are settled.
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FlaskConical } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

export const homeConcepts = [
    {
        key: 'A',
        name: 'The briefing',
        question: 'What should I know this month?',
    },
    { key: 'B', name: 'Money map', question: 'How do the big numbers relate?' },
    {
        key: 'C',
        name: 'The workspace',
        question: 'What deserves my attention?',
    },
];

export function PrototypeSwitcher({
    state,
    concepts = homeConcepts,
    title = 'Home',
    route = home.url,
}: {
    state: Record<string, unknown>;
    concepts?: typeof homeConcepts;
    title?: string;
    route?: typeof home.url;
}) {
    const { url } = usePage();
    const params = new URL(url, 'http://localhost').searchParams;
    const index = Math.max(
        0,
        concepts.findIndex((item) => item.key === params.get('variant')),
    );
    const concept = concepts[index];

    function cycle(direction: number) {
        const next =
            concepts[(index + direction + concepts.length) % concepts.length];
        params.set('variant', next.key);
        router.replace({
            url: route({ query: Object.fromEntries(params) }),
            preserveState: true,
            preserveScroll: false,
        });
    }

    useEffect(() => {
        function handleKey(event: KeyboardEvent) {
            if (
                event.defaultPrevented ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                (event.target instanceof Element &&
                    event.target.closest(
                        'input, textarea, select, [contenteditable], [role="tablist"], [role="slider"], [role="dialog"], [role="menu"], [data-slot="chart"]',
                    ))
            ) {
                return;
            }

            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                cycle(event.key === 'ArrowLeft' ? -1 : 1);
            }
        }
        window.addEventListener('keydown', handleKey);

        return () => window.removeEventListener('keydown', handleKey);
    });

    if (!import.meta.env.DEV) {
        return null;
    }

    return (
        <aside
            aria-label="Prototype controls"
            className="fixed bottom-4 left-1/2 z-40 flex max-w-[calc(100vw-1rem)] -translate-x-1/2 flex-col items-center gap-2 rounded-2xl border bg-background p-2 shadow-2xl ring-1 ring-foreground/10"
        >
            <div className="flex items-center gap-2">
                <FlaskConical className="ml-2 hidden size-4 text-muted-foreground sm:block" />
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Previous concept"
                    onClick={() => cycle(-1)}
                >
                    <ChevronLeft />
                </Button>
                <div className="min-w-36 text-center" aria-live="polite">
                    <p className="text-xs text-muted-foreground">
                        {title} prototype · {index + 1} of {concepts.length}
                    </p>
                    <p className="text-sm font-semibold">
                        {concept.key} · {concept.name}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Next concept"
                    onClick={() => cycle(1)}
                >
                    <ChevronRight />
                </Button>
                <Button asChild size="sm" variant="ghost">
                    <Link href={route()}>Original</Link>
                </Button>
            </div>
            <details className="w-full px-2 text-xs text-muted-foreground">
                <summary className="cursor-pointer text-center">
                    {concept.question} · Inspect state
                </summary>
                <pre className="max-h-40 overflow-auto p-2 text-left">
                    {JSON.stringify(
                        { variant: concept.key, ...state },
                        null,
                        2,
                    )}
                </pre>
            </details>
        </aside>
    );
}
