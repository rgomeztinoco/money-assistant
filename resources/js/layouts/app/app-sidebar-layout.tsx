import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    headerActions,
    viewportConstrained = false,
}: AppLayoutProps) {
    return (
        <AppShell>
            <AppSidebar />
            <AppContent
                className={
                    viewportConstrained
                        ? 'overflow-x-hidden xl:h-[calc(100svh-(--spacing(4)))] xl:max-h-[calc(100svh-(--spacing(4)))] xl:overflow-hidden'
                        : 'overflow-x-hidden'
                }
            >
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={headerActions}
                />
                {children}
            </AppContent>
        </AppShell>
    );
}
