import type { InertiaLinkProps } from '@inertiajs/react';
import { usePage } from '@inertiajs/react';
import { toUrl } from '@/lib/utils';

export function useCurrentUrl() {
    const page = usePage();
    const currentUrlPath = new URL(
        page.url,
        typeof window !== 'undefined'
            ? window.location.origin
            : 'http://localhost',
    ).pathname;

    const isCurrentUrl = (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        startsWith: boolean = false,
    ) => {
        const urlString = toUrl(urlToCheck);

        const comparePath = (path: string): boolean =>
            startsWith
                ? currentUrlPath.startsWith(path)
                : path === currentUrlPath;

        if (!urlString.startsWith('http')) {
            return comparePath(urlString);
        }

        try {
            const absoluteUrl = new URL(urlString);

            return comparePath(absoluteUrl.pathname);
        } catch {
            return false;
        }
    };

    const isCurrentOrParentUrl = (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
    ) => {
        return isCurrentUrl(urlToCheck, true);
    };

    return {
        isCurrentUrl,
        isCurrentOrParentUrl,
    };
}
