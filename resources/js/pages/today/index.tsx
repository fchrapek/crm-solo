import { type MonthData } from '@/components/solo/month-card';
import { SectionHead, SoloButton, Stat } from '@/components/solo/primitives';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface, type DayState } from '@/components/solo/surface';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { useElapsed } from '@/hooks/use-elapsed';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { hoursClock, runningMinutesToday } from '@/lib/dates';
import day from '@/routes/day';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { DayView } from './day';
import { type MoveDay } from './pick-menu';
import { dayLabel, dayPills, dayPlace, type DayNav } from './place';
import { SummaryView } from './summary';
import styles from './today.module.css';

export interface Running {
    id: number;
    started_at: string;
    task_id: number | null;
    task: string | null;
    client: string | null;
    project_id?: number | null;
    project: string | null;
}

export interface Overdue {
    count: number;
    oldest: string | null;
}

/** A pick that left the day for another, with its time on the day. */
export type MovedTask = DayTask & { moved_to: string };

export interface TodayProps {
    state: DayState;
    closed: boolean;
    date: string;
    /** The UTC instant the display calendar's day began. */
    dayStart: string;
    maxPicks: number;
    warnFull: boolean;
    /** Set when the page shows another day than today. */
    day?: DayNav;
    moveDays: MoveDay[];
    moved: MovedTask[];
    picks: DayTask[];
    month: MonthData;
    totals: { logged_minutes: number; clients: number };
    tomorrowPicks: number;
    /** Only with the summary. */
    overdue: Overdue | null;
    running: Running | null;
    timers: Running[];
    /** Running timers no row shows: deleted or no task, a second timer on one task. */
    looseTimers: Running[];
    offPlan: DayTask[];
    /** Unfinished picks of earlier days, waiting until taken or dismissed. */
    carried: (DayTask & { id: number; from: string })[];
    [key: string]: unknown;
}

export default function Today() {
    const props = usePage<SharedData & TodayProps>().props;
    const { t, i18n } = useTranslation();
    // Every prop follows from the date and the running timers, so the whole page reloads.
    useLiveReload();

    return (
        <SoloSurface day="paper">
            <Head title={props.day ? dayLabel(props.day.date, i18n.language) : t('Today')} />
            {props.state === 'timer' && props.running ? <TimerState {...props} running={props.running} /> : null}
            {props.state === 'done' ? <SummaryView {...props} /> : null}
            {props.state === 'paper' ? <DayView {...props} /> : null}
        </SoloSurface>
    );
}

Today.place = ((props, context) =>
    props.day ? dayPlace(props.day, context) : { pills: dayPills(props.state === 'done' ? 'done' : 'today', context.t, props.closed) }) satisfies PlaceResolver<TodayProps>;

/** Minutes logged today plus the running timers that started today, from the display calendar's midnight the server sends. */
function useTodayTotal(dayStart: string, loggedMinutes: number, timers: Running[]): number {
    const now = useNow(15_000);
    if (now === null) return loggedMinutes;

    return (
        loggedMinutes +
        runningMinutesToday(
            dayStart,
            timers.map((timer) => timer.started_at),
            now,
        )
    );
}

/**
 * Figma: Timer A2, the side column. The day's picks as Queue rows: done with its duration, the running one "in progress"
 * on the tint, the next open one "next", the rest bare.
 */
export function TimerQueue({ picks, runningTaskId }: { picks: DayTask[]; runningTaskId: number | null }) {
    const { t } = useTranslation();
    const next = picks.find((p) => !p.done && p.task_id !== runningTaskId);

    return (
        <section className={styles.queue}>
            <SectionHead
                label={t('Today')}
                meta={t('{{done}} of {{count}} done', { done: picks.filter((p) => p.done).length, count: picks.length })}
            />
            <ul className={styles.list}>
                {picks.map((pick) => {
                    const current = !pick.done && pick.task_id === runningTaskId;
                    const status = pick.done
                        ? pick.minutes
                            ? hoursClock(pick.minutes, t)
                            : null
                        : current
                          ? t('queue_current')
                          : pick === next
                            ? t('queue_next')
                            : null;

                    return (
                        <TaskRow
                            key={pick.id ?? pick.task_id}
                            kind="queue"
                            current={current}
                            task={pick}
                            onComplete={() => pick.id && router.post(day.picks.complete(pick.id).url, {}, { preserveScroll: true })}
                            onUncomplete={() => router.delete(day.tasks.uncomplete(pick.task_id).url, { preserveScroll: true })}
                            trailing={status}
                        />
                    );
                })}
            </ul>
        </section>
    );
}

/** Figma: Timer A2 (794:58392, phone 849:20902). The task and its clock in the main column; the day's queue, the total and Stop beside it. */
function TimerState({ running, picks, dayStart, totals, timers }: TodayProps & { running: Running }) {
    const { t, i18n } = useTranslation();
    const { h, m, s } = useElapsed(running.started_at);
    const since = new Intl.DateTimeFormat(i18n.language, { hour: '2-digit', minute: '2-digit' }).format(new Date(running.started_at));
    const total = useTodayTotal(dayStart, totals.logged_minutes, timers);
    const where = [running.client, running.project].filter(Boolean).join(' · ');
    const { pending, stop } = useTimerActions();

    return (
        <main>
            <SoloSurface day="timer" className={`${splitClass} ${styles.timer}`}>
                <div className={`${splitMainClass} ${styles.timerMain}`}>
                    <div className={styles.timerTask}>
                        <h1 className={styles.taskTitle} title={running.task ?? t('Untitled session')}>
                            {running.task ?? t('Untitled session')}
                        </h1>
                        {where && (
                            <p className={styles.where} title={where}>
                                {where}
                            </p>
                        )}
                    </div>
                    <div className={styles.clockBlock}>
                        <p className={styles.clock} aria-label={t('{{h}} h {{m}} min elapsed', { h, m })}>
                            <span className={styles.clockMain}>
                                {h}:{String(m).padStart(2, '0')}
                            </span>
                            <span className={styles.clockSecs}>:{String(s).padStart(2, '0')}</span>
                        </p>
                        <p className={styles.since}>{t('since {{date}}', { date: since })}</p>
                    </div>
                </div>
                <aside className={`${splitSideClass} ${styles.timerSide}`}>
                    {picks.length > 0 && <TimerQueue picks={picks} runningTaskId={running.task_id} />}
                    <Stat value={hoursClock(total, t)} label={t('today in total')} />
                    <div className={styles.actions}>
                        <SoloButton variant="stop" size="md" disabled={pending} onClick={() => stop(running.id)}>
                            {t('Stop')}
                        </SoloButton>
                    </div>
                </aside>
            </SoloSurface>
        </main>
    );
}
