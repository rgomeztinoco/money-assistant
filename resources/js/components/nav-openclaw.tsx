import { Link, usePage } from '@inertiajs/react';
import { Bot, ExternalLink } from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { edit as connectionsEdit } from '@/routes/connections';

export function NavOpenClaw() {
    const { openclaw } = usePage().props;
    const isConfigured =
        openclaw.state === 'configured' && openclaw.launcher_url !== null;

    return (
        <SidebarGroup className="group-data-[collapsible=icon]:p-0">
            <SidebarGroupContent>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            tooltip={{
                                children: `OpenClaw: ${isConfigured ? 'Configured' : 'Unavailable'}`,
                            }}
                        >
                            {isConfigured ? (
                                <a
                                    href={openclaw.launcher_url ?? undefined}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-test="openclaw-launcher"
                                >
                                    <Bot />
                                    <span>OpenClaw</span>
                                    <ExternalLink className="ml-auto group-data-[collapsible=icon]:hidden" />
                                </a>
                            ) : (
                                <Link
                                    href={connectionsEdit({
                                        query: { integration: 'openclaw' },
                                    })}
                                    data-test="openclaw-launcher"
                                >
                                    <Bot />
                                    <span>OpenClaw</span>
                                </Link>
                            )}
                        </SidebarMenuButton>
                        <SidebarMenuBadge
                            className={
                                isConfigured
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-amber-700 dark:text-amber-400'
                            }
                        >
                            {isConfigured ? 'Configured' : 'Unavailable'}
                        </SidebarMenuBadge>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
