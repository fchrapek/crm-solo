import { Building, CalendarCheck, ChartColumn, Contact, Filter, LayoutGrid, Plug, Settings, Users } from 'lucide-react';
import { usePage } from '@inertiajs/react';
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
import leads from '@/routes/leads';
import monthClose from '@/routes/month-close';
import revenue from '@/routes/revenue';
import settings from '@/routes/settings';
import { type NavItem, type SharedData } from '@/types';

export function AppSidebar() {
    const billing = usePage<SharedData>().props.auth.user.account.features.billing;
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
            { title: 'Users', href: settings.show('konto'), icon: Users },
            { title: 'Integrations', href: settings.show('integracje'), icon: Plug },
            { title: 'Settings', href: settings.index(), icon: Settings },
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
                {billing && <NavMain items={financeNavItems} label="Finance" />}
                <NavMain items={systemNavItems} label="System" />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
