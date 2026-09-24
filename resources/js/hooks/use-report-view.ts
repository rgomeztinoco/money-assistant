import { router, usePage } from '@inertiajs/react';

export function useReportView<T extends string>(
    choices: readonly T[],
    fallback: T,
): readonly [T, (value: string) => void] {
    const { url } = usePage();
    const currentUrl = new URL(url, 'http://localhost');
    const requestedView = currentUrl.searchParams.get('view');
    const view = choices.find((choice) => choice === requestedView) ?? fallback;

    function selectView(value: string): void {
        if (!choices.some((choice) => choice === value) || value === view) {
            return;
        }

        currentUrl.searchParams.set('view', value);
        router.push({
            url: `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`,
            preserveState: true,
            preserveScroll: true,
        });
    }

    return [view, selectView];
}
