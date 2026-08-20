import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    LayoutGrid,
    ListChecks,
    MailSearch,
    Store,
    ReceiptText,
    Files,
    Tags,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as categoriesIndex } from '@/routes/categories';
import { index as merchantRulesIndex } from '@/routes/merchant_rules';
import { index as parserProfilesIndex } from '@/routes/parser_profiles';
import { show as reportShow } from '@/routes/reports';
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { index as statementImportsIndex } from '@/routes/statement_imports';
import { index as transactionsIndex } from '@/routes/transactions';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { navigation } = usePage().props;
    const mainNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Transactions',
            href: transactionsIndex(),
            icon: ReceiptText,
        },
        {
            title: 'Review Queue',
            href: reviewQueueIndex(),
            icon: ListChecks,
            badgeCount: navigation.review_queue_count,
        },
        {
            title: 'Reports',
            href: reportShow('PEN'),
            icon: BarChart3,
        },
    ];
    const manageNavItems: NavItem[] = [
        {
            title: 'Statement Imports',
            href: statementImportsIndex(),
            icon: Files,
        },
        {
            title: 'Categories',
            href: categoriesIndex(),
            icon: Tags,
        },
        {
            title: 'Merchant Rules',
            href: merchantRulesIndex(),
            icon: Store,
        },
        {
            title: 'Parser Profiles',
            href: parserProfilesIndex(),
            icon: MailSearch,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label="Everyday" />
                <NavMain
                    items={manageNavItems}
                    label="Manage & automate"
                    priority="secondary"
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
