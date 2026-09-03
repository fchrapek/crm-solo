import { Building, CalendarCheck, ChartColumn, Contact, Filter, LayoutGrid, Plug, Settings, Users } from 'lucide-react';
import React from 'react';

import AppLogo from '@/components/app-logo';
import { MobileAwareLink } from '@/components/mobile-aware-link';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import integrations from '@/routes/integrations';
import leads from '@/routes/leads';
import monthClose from '@/routes/month-close';
import revenue from '@/routes/revenue';
import users from '@/routes/users';
import { type NavItem } from '@/types';

export function AppSidebar() {
    // Three groups: the daily funnel (unlabeled - it IS the product), money
    // things, and admin/config at the bottom (the convention users know from
    // Linear/Stripe/Slack). See the sidebar rework notes.
    const funnelNavItems: NavItem[] = React.useMemo(
        () => [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            { title: 'Leads', href: leads.index(), icon: Filter },
            { title: 'Clients', href: clients.index(), icon: Building },
            { title: 'Contacts', href: contacts.index(), icon: Contact },
        ],
        [],
    );

    const financeNavItems: NavItem[] = React.useMemo(
        () => [
            { title: 'Finances', href: revenue.index(), icon: ChartColumn },
            { title: 'Month Close', href: monthClose.index(), icon: CalendarCheck },
        ],
        [],
    );

    const systemNavItems: NavItem[] = React.useMemo(
        () => [
            { title: 'Users', href: users.index(), icon: Users },
            { title: 'Integrations', href: integrations.index(), icon: Plug },
            { title: 'Settings', href: '/settings', icon: Settings },
        ],
        [],
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <MobileAwareLink href={dashboard()} prefetch>
                                <AppLogo />
                            </MobileAwareLink>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={funnelNavItems} />
                <NavMain items={financeNavItems} label="Finance" />
                <NavMain items={systemNavItems} label="System" />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
