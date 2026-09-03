import { usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { MobileAwareLink } from '@/components/mobile-aware-link';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

import styles from './nav-main.module.css';

export function NavMain({ items = [], label }: { items: NavItem[]; label?: string }) {
    const { t } = useTranslation();
    const page = usePage();

    const getTranslatedTitle = (item: NavItem) => {
        return t(item.title, item.count !== undefined ? { count: item.count } : undefined);
    };

    // Wayfinder hrefs are objects ({ url, method }), page.url is a string with
    // query - normalize both, and keep the item active on its sub-pages.
    const isActive = (item: NavItem) => {
        const href = typeof item.href === 'string' ? item.href : item.href.url;
        const current = page.url.split('?')[0];
        return href === current || (href !== '/' && current.startsWith(`${href}/`));
    };

    return (
        <SidebarGroup className={styles.group}>
            {label && <SidebarGroupLabel>{t(label)}</SidebarGroupLabel>}
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={isActive(item)} tooltip={{ children: getTranslatedTitle(item) }}>
                            <MobileAwareLink href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{getTranslatedTitle(item)}</span>
                            </MobileAwareLink>
                        </SidebarMenuButton>
                        {item.count !== undefined && item.count > 0 && <SidebarMenuBadge>{item.count}</SidebarMenuBadge>}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
