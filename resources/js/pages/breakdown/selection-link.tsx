import { Link, router } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import type { BreakdownProps } from './types';

export function visitSelection(href: string | { url: string }): void {
    const url = new URL(
        typeof href === 'string' ? href : href.url,
        window.location.href,
    );
    const query = url.searchParams;
    const currentView = new URLSearchParams(window.location.search).get('view');

    if (!query.has('view') && currentView !== null) {
        query.set('view', currentView);
    }

    const filters: BreakdownProps['filters'] = {
        category: query.get('category'),
        day: query.get('day'),
        focus: query.get('focus') as BreakdownProps['filters']['focus'],
        merchant: query.get('merchant'),
        attention: query.get('attention') === '1',
        selected: query.has('selected') ? Number(query.get('selected')) : null,
    };

    router.push({
        url: `${url.pathname}${url.search}`,
        props: (props) => ({ ...props, filters }),
        preserveState: true,
        preserveScroll: true,
    });
}

export function SelectionLink({
    href,
    onClick,
    ...props
}: ComponentProps<typeof Link> & {
    href: string | { url: string; method: 'get' };
}) {
    return (
        <Link
            {...props}
            href={href}
            onClick={(event) => {
                onClick?.(event);

                if (
                    event.defaultPrevented ||
                    event.button !== 0 ||
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    props.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();
                visitSelection(href);
            }}
        />
    );
}
