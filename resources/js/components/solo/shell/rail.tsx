import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { logout } from '@/routes';
import profile from '@/routes/profile';
import { type SharedData, type ShellTimer } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { ActionMenuLink } from '../action-menu';
import { initials } from '../actor-mark';
import { useLinkPrefetch } from '../link-prefetch';
import { isOwner, useSections } from '../tab-bar';
import styles from './rail.module.css';

/** Figma: TimerChip. The running timer in the rail; one click stops it. */
export function TimerChip({ timer }: { timer: ShellTimer }) {
    const { t } = useTranslation();
    const elapsed = useElapsed(timer.started_at);
    const { pending, stop } = useTimerActions();
    const what = timer.task ?? t('Untitled session');

    return (
        <button type="button" className={styles.timer} disabled={pending} title={what} aria-label={`${t('Stop')}: ${what}`} onClick={() => stop(timer.id)}>
            <span className={styles.timerChip}>{elapsedClock(elapsed)}</span>
            <span className={styles.timerLabel}>{t('Stop')}</span>
        </button>
    );
}

/** Figma: Rail. Desktop navigation from 768 px, fixed while the content scrolls; below it the tab bar stands in. My profile is the avatar's, never a rail item. */
export function Rail({ timer }: { timer: ShellTimer | null }) {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const { sections, current } = useSections();
    const prefetch = useLinkPrefetch();

    return (
        <header className={styles.rail}>
            <div className={styles.top}>
                <nav className={styles.nav} aria-label={t('Main navigation')}>
                    {sections.map(({ key, label, href, icon }) =>
                        icon === undefined ? null : (
                            <Link key={key} href={href} prefetch={prefetch(href)} className={styles.item} aria-current={current === key ? 'page' : undefined}>
                                <span className={styles.field}>
                                    <Icon name={icon} size={24} filled={current === key} />
                                </span>
                                {label}
                            </Link>
                        ),
                    )}
                </nav>
            </div>
            <div className={styles.bottom}>
                {timer && <TimerChip timer={timer} />}
                <AccountMenu user={auth.user} current={current === 'profile'} />
            </div>
        </header>
    );
}

/** Figma: Menu · Account (1557:346650). Who is signed in, My profile and the way out; while My profile is open the avatar is lit like a current rail item. */
export function AccountMenu({ user, current = false }: { user: SharedData['auth']['user']; current?: boolean }) {
    const { t } = useTranslation();
    const owner = isOwner(user);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className={styles.avatar} aria-label={user.name} data-current={current ? '' : undefined}>
                {initials(user.name)}
            </DropdownMenuTrigger>
            <DropdownMenuContent side="right" align="end" className={styles.accountMenu}>
                <DropdownMenuLabel className={styles.account}>
                    <span className={styles.accountName}>{user.name}</span>
                    <span className={styles.accountMeta}>{user.email}</span>
                    <span className={styles.accountRole}>{owner ? t('owner') : t('user')}</span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <ActionMenuLink href={profile.show()}>{t('My profile')}</ActionMenuLink>
                <DropdownMenuSeparator />
                <ActionMenuLink href={logout()} as="button" method="post">
                    {t('Log out')}
                </ActionMenuLink>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
