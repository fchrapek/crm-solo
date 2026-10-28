import { ManualTimeEntryDialog } from '@/components/manual-time-entry-dialog';
import { BottomAction } from '@/components/solo/bottom-action';
import { SectionHead, SoloButton, Stat, StatRow } from '@/components/solo/primitives';
import { Icon } from '@/components/ui/icon';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { durationText, minutesSince, statDuration } from '@/lib/dates';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { liveMinutes } from './format';
import styles from './task-page.module.css';
import { LIVE_PROPS, type TaskTime } from './types';

/** Figma: Zadanie · timer liczy (922:21795): while a timer runs, "dziś" says since when, after a status dot. */
function TodayLabel({ time }: { time: TaskTime }) {
    const { t } = useTranslation();
    const since = time.running_since;
    if (since === null) return t('today');

    return (
        <span className={styles.liveLabel}>
            <span className={styles.liveDot} aria-hidden="true" />
            {t('today · running since {{time}}', { time: since })}
        </span>
    );
}

/** Figma: A2f / Stat row under the phone meta (849:20996): today's and all-time minutes, running timers included. */
export function TimeStats({ time, now }: { time: TaskTime; now: number }) {
    const { t } = useTranslation();
    const minutes = liveMinutes(time, now);

    return (
        <StatRow className={styles.phoneStats}>
            <Stat value={statDuration(minutes.today, t)} label={<TodayLabel time={time} />} />
            {minutes.total > 0 && <Stat value={statDuration(minutes.total, t)} label={t('total')} />}
        </StatRow>
    );
}

/**
 * Figma: Czas (770:56741, with Zaloguj czas 740:18599): today's minutes, the total and the owner's own Start or Stop.
 * Phones show the figures in TimeStats and keep only the bottom action from here.
 */
export function TimeSection({ task, time, now }: { task: { id: number; name: string; client?: string | null }; time: TaskTime; now: number }) {
    const { t } = useTranslation();
    const timer = useTimerActions();
    const [logging, setLogging] = useState(false);
    const minutes = liveMinutes(time, now);
    const own = time.own_running;

    return (
        <section className={styles.time} aria-labelledby="task-time">
            <SectionHead
                kind="body"
                className={styles.timeHead}
                label={<span id="task-time">{t('Time')}</span>}
                meta={minutes.total > 0 ? t('{{time}} total', { time: durationText(minutes.total, t) }) : undefined}
            />
            <div className={styles.timeRow}>
                <div className={styles.timeFigure}>
                    <Stat value={statDuration(minutes.today, t)} label={<TodayLabel time={time} />} />
                </div>
                <div className={styles.timeActions}>
                    <BottomAction
                        secondary={
                            <SoloButton variant="text" size="sm" className={styles.logTime} onClick={() => setLogging(true)}>
                                {t('Log time')}
                            </SoloButton>
                        }
                    >
                        {own !== null ? (
                            <SoloButton variant="stop" size="md" disabled={timer.pending} onClick={() => void timer.stop(own.id)}>
                                {t('Stop')}
                                {own.started_at !== null && (
                                    <span className={styles.timerElapsed}>{statDuration(minutesSince(own.started_at, now), t)}</span>
                                )}
                            </SoloButton>
                        ) : (
                            <SoloButton variant="outline" size="md" disabled={timer.pending} onClick={() => void timer.start(task.id)}>
                                <Icon name="play" size={16} filled className={styles.timerIcon} />
                                {t('Start')}
                            </SoloButton>
                        )}
                    </BottomAction>
                </div>
            </div>
            <ManualTimeEntryDialog open={logging} onOpenChange={setLogging} task={task} onSaved={() => router.reload({ only: LIVE_PROPS })} />
        </section>
    );
}
