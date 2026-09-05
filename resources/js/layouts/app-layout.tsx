import { usePage } from '@inertiajs/react';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({
    children,
    breadcrumbs = [],
    headerActions,
    viewportConstrained = false,
}: AppLayoutProps) {
    const { sidebarOpen } = usePage().props;

    return (
        <SidebarProvider defaultOpen={sidebarOpen}>
            <AppSidebar />
            <SidebarInset
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
            </SidebarInset>
        </SidebarProvider>
    );
}
