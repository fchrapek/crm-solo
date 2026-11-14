import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { dashboard, logout } from '@/routes';
import { type SharedData, type ShellTimer } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { initials } from '../actor-mark';
import { useSections } from '../tab-bar';
import { DogStill } from './dog-still';
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

/** Figma: Rail. Desktop navigation from 768 px, fixed while the content scrolls; below it the tab bar stands in. */
export function Rail({ timer }: { timer: ShellTimer | null }) {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const { sections, current } = useSections();

    return (
        <header className={styles.rail}>
            <div className={styles.top}>
                <Link href={dashboard()} className={styles.logo} aria-label="CRM Solo">
                    <DogStill size={56} />
                </Link>
                <nav className={styles.nav} aria-label={t('Main navigation')}>
                    {sections.map(({ key, label, href, icon }) => (
                        <Link key={key} href={href} className={styles.item} aria-current={current === key ? 'page' : undefined}>
                            <span className={styles.field}>
                                <Icon name={icon} size={24} filled={current === key} />
                            </span>
                            {label}
                        </Link>
                    ))}
                </nav>
            </div>
            <div className={styles.bottom}>
                {timer && <TimerChip timer={timer} />}
                <DropdownMenu>
                    <DropdownMenuTrigger className={styles.avatar} aria-label={auth.user.name}>
                        {initials(auth.user.name)}
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="right" align="end">
                        <DropdownMenuItem asChild>
                            <Link href={logout()} as="button" method="post">
                                {t('Log out')}
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
