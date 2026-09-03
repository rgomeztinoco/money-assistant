import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
    headerActions,
    viewportConstrained,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
    headerActions?: React.ReactNode;
    viewportConstrained?: boolean;
}) {
    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            headerActions={headerActions}
            viewportConstrained={viewportConstrained}
        >
            {children}
        </AppLayoutTemplate>
    );
}
