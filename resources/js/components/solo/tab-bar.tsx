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
    ['today', ['/jutro', '/tydzien', '/zadania']],
    ['clients', ['/clients', '/tasks', '/month-close', '/sms']],
    ['contacts', ['/contacts', '/leads']],
    ['settings', ['/settings']],
];

/** The tab a URL belongs to, or null for a page none of them owns (Finanse and the rest stay reachable by URL). */
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

export interface Section {
    key: TabBarSection;
    label: string;
    href: ReturnType<typeof dashboard>;
    icon: 'calendar' | 'client' | 'contacts' | 'settings';
}

/** The four sections the rail and the tab bar both list. */
export function useSections(): { sections: Section[]; current: TabBarSection | null } {
    const { t } = useTranslation();
    const { url } = usePage();

    return {
        current: tabBarSection(url),
        sections: [
            { key: 'today', label: t('Today'), href: dashboard(), icon: 'calendar' },
            { key: 'clients', label: t('Clients'), href: clients.index(), icon: 'client' },
            { key: 'contacts', label: t('Contacts'), href: contacts.index(), icon: 'contacts' },
            { key: 'settings', label: t('Settings'), href: settings.index(), icon: 'settings' },
        ],
    };
}

/** Figma: TabBar. What the rail becomes under 768 px; the shell renders both and CSS shows one. */
export function TabBar() {
    const { t } = useTranslation();
    const { sections, current } = useSections();

    return (
        <nav className={styles.tabBar} data-solo-tab-bar="" aria-label={t('Main navigation')}>
            {sections.map(({ key, label, href, icon }) => (
                <Link key={key} href={href} className={styles.tab} aria-current={current === key ? 'page' : undefined}>
                    <span className={styles.tabField}>
                        <Icon name={icon} size={24} filled={current === key} />
                    </span>
                    <span>{label}</span>
                </Link>
            ))}
        </nav>
    );
}
