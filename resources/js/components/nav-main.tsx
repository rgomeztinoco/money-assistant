import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items = [],
    label,
    priority = 'primary',
}: {
    items: NavItem[];
    label: string;
    priority?: 'primary' | 'secondary';
}) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup
            className={priority === 'primary' ? 'px-2 py-0' : 'mt-4 px-2 py-0'}
        >
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                            className={
                                priority === 'secondary'
                                    ? 'text-sidebar-foreground/75'
                                    : undefined
                            }
                        >
                            <Link
                                href={item.href}
                                prefetch
                                data-test={`nav-${item.title.toLowerCase().replaceAll(' ', '-')}`}
                            >
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                        {item.badgeCount !== undefined &&
                            item.badgeCount > 0 && (
                                <SidebarMenuBadge>
                                    <span
                                        aria-label={`${item.badgeCount} outstanding reviews`}
                                        data-test={`nav-${item.title.toLowerCase().replaceAll(' ', '-')}-count`}
                                    >
                                        {item.badgeCount}
                                    </span>
                                </SidebarMenuBadge>
                            )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
