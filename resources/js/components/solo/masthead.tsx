import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { dashboard, logout } from '@/routes';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import settings from '@/routes/settings';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { initials } from './actor-mark';
import { CommandPalette, openSearch } from './command-palette';
import { DogOutline, DogReversed } from './logos';
import styles from './masthead.module.css';
import { soloPillClass } from './primitives';
import { TabBar } from './tab-bar';

interface MastheadProps {
    /** Which page of the top nav is current; the day screens pass 'today', the error page null. Leady and Finanse are off the menu for now, still reachable by URL. */
    current?: 'today' | 'clients' | 'contacts' | 'settings' | null;
    /** Paper shows the outline dog; the coloured states show the reversed one. */
    reversed?: boolean;
}

/** Desktop from 768 px; below it the same call renders the bottom tab bar instead. */
export function Masthead({ current = 'today', reversed = false }: MastheadProps) {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const Logo = reversed ? DogReversed : DogOutline;

    const items = [
        { key: 'today', label: t('Today'), href: dashboard() },
        { key: 'clients', label: t('Clients'), href: clients.index() },
        { key: 'contacts', label: t('Contacts'), href: contacts.index() },
        { key: 'settings', label: t('Settings'), href: settings.index() },
    ] as const;

    return (
        <>
            <header className={styles.masthead}>
                <Link href={dashboard()} className={styles.logo} aria-label={t('Today')}>
                    <Logo />
                </Link>
                <nav className={styles.nav} aria-label={t('Main navigation')}>
                    {items.map((item) => (
                        <Link key={item.key} href={item.href} className={styles.navItem} aria-current={current === item.key ? 'page' : undefined}>
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <div className={styles.end}>
                    <button type="button" className={soloPillClass} onClick={openSearch}>
                        ⌘K&nbsp;&nbsp;{t('Search')}
                    </button>
                    <DropdownMenu>
                        <DropdownMenuTrigger className={styles.avatar} aria-label={auth.user.name}>
                            {initials(auth.user.name)}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <Link href={logout()} as="button" method="post">
                                    {t('Log out')}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>
            <TabBar />
            <CommandPalette />
        </>
    );
}
