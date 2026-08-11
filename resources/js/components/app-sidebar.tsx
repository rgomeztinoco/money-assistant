import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRightLeft,
    BarChart3,
    LayoutGrid,
    ListChecks,
    MailSearch,
    Store,
    ReceiptText,
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
import { index as dailyExchangeRatesIndex } from '@/routes/daily_exchange_rates';
import { index as insightsIndex } from '@/routes/insights';
import { index as merchantRulesIndex } from '@/routes/merchant_rules';
import { index as parserProfilesIndex } from '@/routes/parser_profiles';
import { index as reviewQueueIndex } from '@/routes/review_queue';
import { index as transactionsIndex } from '@/routes/transactions';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { review_queue } = usePage().props;
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
            badgeCount: review_queue.outstanding_count,
        },
        {
            title: 'Insights',
            href: insightsIndex(),
            icon: BarChart3,
        },
    ];
    const manageNavItems: NavItem[] = [
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
            title: 'Daily Exchange Rates',
            href: dailyExchangeRatesIndex(),
            icon: ArrowRightLeft,
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
                <NavMain items={mainNavItems} />
                <NavMain items={manageNavItems} label="Manage" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
