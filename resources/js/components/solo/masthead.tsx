import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { dashboard, logout, sessions } from '@/routes';
import clients from '@/routes/clients';
import contacts from '@/routes/contacts';
import integrations from '@/routes/integrations';
import leads from '@/routes/leads';
import monthClose from '@/routes/month-close';
import revenue from '@/routes/revenue';
import users from '@/routes/users';
import { type SharedData } from '@/types';
import { DogOutline, DogReversed } from './logos';
import styles from './masthead.module.css';

interface MastheadProps {
    /** Which page of the top nav is current; the day screens pass 'today'. */
    current?: 'today' | 'clients' | 'leads' | 'finances';
    /** Paper shows the outline dog; the coloured states show the reversed one. */
    reversed?: boolean;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export function Masthead({ current = 'today', reversed = false }: MastheadProps) {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const Logo = reversed ? DogReversed : DogOutline;

    const items = [
        { key: 'today', label: t('Today'), href: dashboard() },
        { key: 'clients', label: t('Clients'), href: clients.index() },
        { key: 'leads', label: t('Leads'), href: leads.index() },
        { key: 'finances', label: t('Finances'), href: revenue.index() },
    ] as const;

    return (
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
                <DropdownMenu>
                    <DropdownMenuTrigger className={styles.navItem}>{t('More')}</DropdownMenuTrigger>
                    <DropdownMenuContent align="start">
                        <DropdownMenuItem asChild>
                            <Link href={contacts.index()}>{t('Contacts')}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={monthClose.index()}>{t('Month Close')}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={sessions()}>{t('Agent sessions')}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href={users.index()}>{t('Users')}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={integrations.index()}>{t('Integrations')}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/settings">{t('Settings')}</Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </nav>
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
        </header>
    );
}
