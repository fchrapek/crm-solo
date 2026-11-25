import { ActionMenu } from '@/components/solo/action-menu';
import { menuHeadClass, menuLabelClass, menuValueClass } from '@/components/solo/menu';
import { DropdownMenuItem, DropdownMenuLabel } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { type ShellTimer } from '@/types';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './timer-switch.module.css';

function Clock({ startedAt }: { startedAt: string }) {
    return <>{elapsedClock(useElapsed(startedAt))}</>;
}

/**
 * Figma: Several timers, C · List (1276:6245, sheet 1276:6351). With more than one timer running, "1 z 2" opens the
 * list of them and a choice shows that one; with one timer there is nothing to switch and nothing is drawn.
 */
export function TimerSwitch({ timers, shown }: { timers: Pick<ShellTimer, 'id' | 'started_at' | 'task' | 'client'>[]; shown: number }) {
    const { t } = useTranslation();

    if (timers.length < 2) {
        return null;
    }
    const place = Math.max(0, timers.findIndex((timer) => timer.id === shown)) + 1;

    return (
        <ActionMenu
            trigger={
                <button type="button" className={styles.switch} aria-label={t('Running timers')}>
                    {t('{{done}} of {{count}}', { done: place, count: timers.length })}
                    <Icon name="chevron-down" size={16} />
                </button>
            }
        >
            <DropdownMenuLabel className={menuHeadClass}>{t('Running timers')}</DropdownMenuLabel>
            {timers.map((timer) => (
                <DropdownMenuItem
                    key={timer.id}
                    data-state={timer.id === shown ? 'checked' : undefined}
                    onSelect={() => router.reload({ data: { timer: timer.id } })}
                >
                    <span className={menuLabelClass}>{[timer.task ?? t('Untitled session'), timer.client].filter(Boolean).join(' · ')}</span>
                    <span className={menuValueClass}>
                        <Clock startedAt={timer.started_at} />
                    </span>
                </DropdownMenuItem>
            ))}
        </ActionMenu>
    );
}
