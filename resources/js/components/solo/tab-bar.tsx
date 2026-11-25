import { Icon } from '@/components/ui/icon';
import { dashboard } from '@/routes';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import profile from '@/routes/profile';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { initials } from './actor-mark';
import { useLinkPrefetch } from './link-prefetch';
import styles from './mobile.module.css';

export type TabBarSection = 'today' | 'clients' | 'contacts' | 'settings' | 'profile';

const SECTION_PREFIXES: ReadonlyArray<[TabBarSection, ReadonlyArray<string>]> = [
    ['today', ['/tomorrow', '/week', '/all-tasks']],
    ['clients', ['/clients', '/tasks', '/month-close', '/sms']],
    ['contacts', ['/contacts', '/leads']],
    ['settings', ['/settings']],
    ['profile', ['/profile']],
];

/** The section a URL belongs to, or null for a page none of them owns (Finances and the rest stay reachable by URL). */
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

/** Whether the signed-in user owns the account; the shared prop is the raw column, 1 or 0 from the database. */
export function isOwner(user: { owner?: unknown } | null | undefined): boolean {
    return Boolean(Number(user?.owner));
}

export interface Section {
    key: TabBarSection;
    label: string;
    href: ReturnType<typeof dashboard>;
    /** None for My profile, which shows the user's initials. */
    icon?: 'calendar' | 'client' | 'contacts' | 'settings';
}

/**
 * The sections the rail and the tab bar list: an owner's fourth is Settings, a member's is My profile, which the rail
 * leaves to the account menu.
 */
export function useSections(): { sections: Section[]; current: TabBarSection | null; owner: boolean } {
    const { t } = useTranslation();
    const { url, props } = usePage<SharedData>();
    const owner = isOwner(props.auth?.user);

    return {
        current: tabBarSection(url),
        owner,
        sections: [
            { key: 'today', label: t('Today'), href: dashboard(), icon: 'calendar' },
            { key: 'clients', label: t('Clients'), href: clients.index(), icon: 'client' },
            { key: 'contacts', label: t('Contacts'), href: contacts.index(), icon: 'contacts' },
            owner
                ? { key: 'settings', label: t('Settings'), href: settings.index(), icon: 'settings' }
                : { key: 'profile', label: t('My profile'), href: profile.show() },
        ],
    };
}

/** Figma: TabBar. What the rail becomes under 768 px; the shell renders both and CSS shows one. An owner reaches My profile from Settings, so it lights Settings. */
export function TabBar() {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const { sections, current, owner } = useSections();
    const lit = current === 'profile' && owner ? 'settings' : current;
    const prefetch = useLinkPrefetch();

    return (
        <nav className={styles.tabBar} data-solo-tab-bar="" aria-label={t('Main navigation')}>
            {sections.map(({ key, label, href, icon }) => (
                <Link key={key} href={href} prefetch={prefetch(href)} className={styles.tab} aria-current={lit === key ? 'page' : undefined}>
                    <span className={styles.tabField}>
                        {icon ? (
                            <Icon name={icon} size={24} filled={lit === key} />
                        ) : (
                            <span className={styles.tabAvatar} aria-hidden="true">
                                {initials(auth?.user?.name ?? '')}
                            </span>
                        )}
                    </span>
                    <span>{label}</span>
                </Link>
            ))}
        </nav>
    );
}
