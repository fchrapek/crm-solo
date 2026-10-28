import { Icon } from '@/components/ui/icon';
import { dashboard } from '@/routes';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import settings from '@/routes/settings';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './mobile.module.css';

export type TabBarSection = 'today' | 'clients' | 'contacts' | 'settings';

const SECTION_PREFIXES: ReadonlyArray<[TabBarSection, ReadonlyArray<string>]> = [
    ['today', ['/jutro', '/zadania']],
    ['clients', ['/clients', '/tasks', '/month-close']],
    ['contacts', ['/contacts']],
    ['settings', ['/settings']],
];

/** The tab a URL belongs to, or null for a page none of them owns (Leady, Finanse and the rest stay reachable by URL). */
export function tabBarSection(url: string): TabBarSection | null {
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    if (path === '/') {
        return 'today';
    }

    for (const [section, prefixes] of SECTION_PREFIXES) {
        if (prefixes.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))) {
            return section;
        }
    }

    return null;
}

/** Figma: Mobile / Tab bar. Always rendered beside the masthead or app shell; CSS shows one per width. */
export function TabBar() {
    const { t } = useTranslation();
    const { url } = usePage();
    const current = tabBarSection(url);

    const tabs = [
        { key: 'today', label: t('Today'), href: dashboard(), icon: 'calendar' },
        { key: 'clients', label: t('Clients'), href: clients.index(), icon: 'client' },
        { key: 'contacts', label: t('Contacts'), href: contacts.index(), icon: 'contacts' },
        { key: 'settings', label: t('Settings'), href: settings.index(), icon: 'settings' },
    ] as const;

    return (
        <nav className={styles.tabBar} data-solo-tab-bar="" aria-label={t('Main navigation')}>
            {tabs.map(({ key, label, href, icon }) => (
                <Link key={key} href={href} className={styles.tab} aria-current={current === key ? 'page' : undefined}>
                    <Icon name={icon} size={24} filled={current === key} />
                    <span>{label}</span>
                </Link>
            ))}
        </nav>
    );
}
