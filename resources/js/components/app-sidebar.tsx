import { Link, usePage } from '@inertiajs/react';
import {
    Files,
    House,
    Mail,
    ReceiptText,
    Store,
    Tags,
    TrendingUp,
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
import {
    reportingQuery as buildReportingQuery,
    reportingQueryFromUrl,
    reportingSelection,
} from '@/lib/reporting-query';
import { home } from '@/routes';
import { index as breakdownIndex } from '@/routes/breakdown';
import { index as categoriesIndex } from '@/routes/categories';
import { gmail as gmailDataSource } from '@/routes/data_sources';
import { index as merchantRulesIndex } from '@/routes/merchant_rules';
import { index as statementImportsIndex } from '@/routes/statement_imports';
import { index as trendsIndex } from '@/routes/trends';
import type { Currency, NavItem, ReportingPeriod } from '@/types';

export function AppSidebar() {
    const page = usePage<{
        currency_filter?: Currency | null;
        period?: ReportingPeriod;
    }>();
    const reportingQuery = page.props.period
        ? buildReportingQuery(
              page.props.currency_filter ?? null,
              reportingSelection(page.props.period),
          )
        : reportingQueryFromUrl(page.url);
    const mainNavItems: NavItem[] = [
        {
            title: 'Home',
            href: home({ query: reportingQuery }),
            icon: House,
        },
        {
            title: 'Breakdown',
            href: breakdownIndex({ query: reportingQuery }),
            icon: ReceiptText,
        },
        {
            title: 'Trends',
            href: trendsIndex({ query: reportingQuery }),
            icon: TrendingUp,
        },
    ];
    const dataSourceNavItems: NavItem[] = [
        {
            title: 'Gmail',
            href: gmailDataSource(),
            icon: Mail,
        },
        {
            title: 'Statement Imports',
            href: statementImportsIndex(),
            icon: Files,
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
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={home({ query: reportingQuery })}
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} label="Money" />
                <NavMain
                    items={dataSourceNavItems}
                    label="Data sources"
                    priority="secondary"
                />
                <NavMain
                    items={manageNavItems}
                    label="Manage"
                    priority="secondary"
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
