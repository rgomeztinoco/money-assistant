import { Link } from '@inertiajs/react';
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
import { home } from '@/routes';
import { index as breakdownIndex } from '@/routes/breakdown';
import { index as categoriesIndex } from '@/routes/categories';
import { gmail as gmailDataSource } from '@/routes/data_sources';
import { index as merchantRulesIndex } from '@/routes/merchant_rules';
import { index as statementImportsIndex } from '@/routes/statement_imports';
import { index as trendsIndex } from '@/routes/trends';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const mainNavItems: NavItem[] = [
        {
            title: 'Home',
            href: home(),
            icon: House,
        },
        {
            title: 'Breakdown',
            href: breakdownIndex(),
            icon: ReceiptText,
        },
        {
            title: 'Trends',
            href: trendsIndex(),
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
                            <Link href={home()} prefetch>
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
