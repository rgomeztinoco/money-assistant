import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    actions,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    actions?: ReactNode;
}) {
    return (
        <header className="flex min-h-16 shrink-0 flex-wrap items-center justify-between gap-x-2 gap-y-1 border-b border-sidebar-border/50 px-4 py-2 transition-[width,height] ease-linear md:flex-nowrap md:py-0 group-has-data-[collapsible=icon]/sidebar-wrapper:md:min-h-12">
            <div className="flex shrink-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {actions !== undefined && (
                <div className="flex min-w-0 flex-1 basis-full justify-end md:basis-auto">
                    {actions}
                </div>
            )}
        </header>
    );
}
