import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { type ShellTimer } from '@/types';
import { useTranslation } from 'react-i18next';
import { SoloButton } from '../primitives';
import styles from './timer-strip.module.css';
import { TimerSwitch } from './timer-switch';

/** Figma: TimerStrip. The running timer on a phone, fixed above the tab bar; the rail chip takes over from 768 px. */
export function TimerStrip({ timer, timers = [] }: { timer: ShellTimer; timers?: ShellTimer[] }) {
    const { t } = useTranslation();
    const elapsed = useElapsed(timer.started_at);
    const { pending, stop } = useTimerActions();

    return (
        <div className={styles.strip} data-shell-strip="" role="status" aria-label={t('Timer running')}>
            <span className={styles.time}>{elapsedClock(elapsed)}</span>
            <span className={styles.title} title={timer.task ?? t('Untitled session')}>
                {timer.task ?? t('Untitled session')}
            </span>
            <TimerSwitch timers={timers} shown={timer.id} />
            <SoloButton variant="on-colour" size="control" disabled={pending} onClick={() => stop(timer.id)}>
                {t('Stop')}
            </SoloButton>
        </div>
    );
}
