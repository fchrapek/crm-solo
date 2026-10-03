import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { dayMonth, formatMinutes, isoWeek, longDate, parseDay, weekdayName } from '@/components/solo/day-format';
import { DayRow } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { MonthCard, type MonthData } from '@/components/solo/month-card';
import { Eyebrow, Poster, SectionHead, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { Toaster } from '@/components/ui/sonner';
import { jsonRequest } from '@/lib/json-request';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import timeEntries from '@/routes/time-entries';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import styles from './today.module.css';

type DayState = 'paper' | 'timer' | 'done';

interface Running {
    id: number;
    started_at: string;
    task_id: number | null;
    task: string | null;
    client: string | null;
    project: string | null;
}

interface TodayProps {
    state: DayState;
    closed: boolean;
    date: string;
    maxPicks: number;
    picks: DayTask[];
    month: MonthData;
    totals: { logged_minutes: number; clients: number };
    tomorrowPicks: number;
    running: Running | null;
    timers: Running[];
    /** Running timers no row shows: deleted or no task, a second timer on one task. */
    looseTimers: Running[];
    offPlan: DayTask[];
    [key: string]: unknown;
}

/**
 * Picks up timers started or stopped outside this tab, and the turn of the
 * day, on an interval and at once when the tab regains focus. It reloads every
 * prop: they all follow from the date and the running timers, so a list of
 * "live" ones could only fall behind.
 */
function useLiveDay() {
    usePoll(30_000);

    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') router.reload();
        };
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, []);
}

export default function Today() {
    const props = usePage<SharedData & TodayProps>().props;
    const { t } = useTranslation();
    useLiveDay();

    return (
        <div className={styles.page} data-day={props.state}>
            <Head title={t('Today')} />
            <div className={styles.frame}>
                <Masthead reversed={props.state !== 'paper'} />
                {props.state === 'timer' && props.running ? <TimerState {...props} running={props.running} /> : null}
                {props.state === 'done' ? <DoneState {...props} /> : null}
                {props.state === 'paper' ? <PaperState {...props} /> : null}
            </div>
            <Toaster className={styles.toasts} position="bottom-right" />
        </div>
    );
}

function useDateLine(date: string) {
    const { t, i18n } = useTranslation();
    const d = parseDay(date);

    const week = t('week {{n}}', { n: isoWeek(d) });

    return {
        d,
        line: `${longDate(d, i18n.language)} · ${week}`,
        short: `${dayMonth(d, i18n.language)} · ${week}`,
        weekday: weekdayName(d, i18n.language),
    };
}

function complete(pick: DayTask) {
    if (pick.id) {
        router.post(day.picks.complete(pick.id).url, {}, { preserveScroll: true });
    }
}

/** Takes a mistaken tick back; any timer the tick stopped stays stopped. */
function uncomplete(row: DayTask) {
    router.delete(day.tasks.uncomplete(row.task_id).url, { preserveScroll: true });
}

/** crypto.randomUUID needs a secure context; getRandomValues does not. */
function requestId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/**
 * The id of a start whose outcome this page does not know yet, per task. A
 * retry reuses it, so a start that committed but lost its response is
 * returned rather than doubled. It is forgotten once a response arrives or a
 * reload shows the day as the server holds it.
 */
const unresolvedStarts = new Map<number, string>();

/**
 * Start and Stop with one request in flight at a time. A start carries a
 * request id the server honours, so a click that slips past the guard
 * still opens one timer. Failures surface as a toast, and the day reloads
 * either way so the screen shows what the server holds.
 */
function useTimerActions() {
    const { t } = useTranslation();
    const busy = useRef(false);
    const [pending, setPending] = useState(false);

    const run = useCallback(async (send: () => Promise<unknown>, failure: string, settled?: () => void) => {
        if (busy.current) return;
        busy.current = true;
        setPending(true);
        try {
            await send();
            settled?.();
        } catch (error) {
            toast.error(failure, { description: error instanceof Error ? error.message : undefined });
        }
        router.reload({
            onSuccess: () => settled?.(),
            onFinish: () => {
                busy.current = false;
                setPending(false);
            },
        });
    }, []);

    const start = (taskId: number) => {
        const id = unresolvedStarts.get(taskId) ?? requestId();
        unresolvedStarts.set(taskId, id);
        const send = async () => {
            const entry = await jsonRequest<{ end_time: string | null }>(timeEntries.start(taskId).url, 'POST', { request_id: id });
            // The kept id belonged to a start that landed and has since been stopped: this click is a new timer,
            // whose id is kept in turn until its own outcome is known.
            if (entry.end_time !== null) {
                const replacement = requestId();
                unresolvedStarts.set(taskId, replacement);
                await jsonRequest(timeEntries.start(taskId).url, 'POST', { request_id: replacement });
            }
        };
        return run(
            send,
            t('The timer did not start.'),
            () => unresolvedStarts.delete(taskId),
        );
    };

    return {
        pending,
        start,
        stop: (entryId: number) => run(() => jsonRequest(timeEntries.stop(entryId).url, 'POST'), t('The timer did not stop.')),
    };
}

const focusView = (timerId: number) => dashboard({ query: { widok: 'timer', timer: timerId } });

/** Figma: the running row on the default Dziś screen. Live dot, elapsed time, Stop. */
function RunningControls({ running }: { running: Running }) {
    const { t } = useTranslation();
    const { h, m } = useElapsed(running.started_at);
    const { pending, stop } = useTimerActions();

    return (
        <span className={styles.running}>
            <span className={styles.liveDot} aria-hidden="true" />
            <span className={styles.elapsed} aria-label={t('{{h}} h {{m}} min elapsed', { h, m })}>
                {h}:{String(m).padStart(2, '0')}
            </span>
            <SoloButton size="sm" disabled={pending} onClick={() => stop(running.id)}>
                ■ {t('Stop')}
            </SoloButton>
        </span>
    );
}

/** Figma: Poza planem. Tasks with a timer today that were not picked; ticks here never count toward the plan. */
function OffPlanSection({ date, rows, timers, canAdopt }: { date: string; rows: DayTask[]; timers: Running[]; canAdopt: boolean }) {
    const { t } = useTranslation();
    if (rows.length === 0) return null;
    const live = rows.filter((r) => r.running_id).length;
    const logged = rows.reduce((n, r) => n + (r.minutes ?? 0), 0);
    const meta = [live > 0 ? t('{{count}} running', { count: live }) : null, logged > 0 ? formatMinutes(logged) : null].filter(Boolean).join(' · ');

    return (
        <div>
            <SectionHead label={t('Off plan')} meta={meta} quiet />
            <ul className={styles.list}>
                {rows.map((row) => {
                    const timer = row.running_id ? timers.find((tm) => tm.id === row.running_id) : undefined;
                    return (
                        <TaskRow
                            key={row.task_id}
                            task={row}
                            onComplete={() => router.post(day.tasks.complete(row.task_id).url, {}, { preserveScroll: true })}
                            onUncomplete={() => uncomplete(row)}
                            href={timer ? focusView(timer.id) : undefined}
                            trailing={
                                <>
                                    {timer ? <RunningControls running={timer} /> : row.minutes ? formatMinutes(row.minutes) : null}
                                    {!row.done && canAdopt && (
                                        <SoloButton
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => router.post(day.picks.store().url, { task_id: row.task_id, date }, { preserveScroll: true })}
                                        >
                                            {t('+ To the plan')}
                                        </SoloButton>
                                    )}
                                </>
                            }
                        />
                    );
                })}
            </ul>
        </div>
    );
}

/** Every running timer stays visible and stoppable; these are the ones no task row carries. */
function LooseTimersSection({ timers }: { timers: Running[] }) {
    const { t } = useTranslation();
    if (timers.length === 0) return null;

    return (
        <div>
            <SectionHead label={t('Other running timers')} meta={t('{{count}} running', { count: timers.length })} quiet />
            <ul className={styles.list}>
                {timers.map((timer) => (
                    <TaskRow
                        key={timer.id}
                        task={{ task_id: timer.task_id ?? 0, name: timer.task ?? t('Untitled session'), client: timer.client, project: timer.project, done: false }}
                        href={focusView(timer.id)}
                        trailing={<RunningControls running={timer} />}
                    />
                ))}
            </ul>
        </div>
    );
}

function PaperState({ date, picks, maxPicks, month, closed, timers, offPlan, looseTimers }: TodayProps) {
    const { t } = useTranslation();
    const { short, weekday } = useDateLine(date);
    const errors = usePage().props.errors as Record<string, string>;
    const { pending, start } = useTimerActions();
    const open = picks.filter((p) => !p.done);
    const allDone = picks.length > 0 && open.length === 0;
    const timerFor = (pick: DayTask) => (pick.running_id ? timers.find((tm) => tm.id === pick.running_id) : undefined);

    return (
        <main className={styles.grid}>
            <div className={styles.head}>
                <DayRow current="today" closed={closed}>
                    {short}
                </DayRow>
                <Poster>{weekday}</Poster>
            </div>
            <div className={styles.main}>
                <div>
                    <SectionHead label={t('Today')} meta={t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks })} />
                    <ul className={styles.list}>
                        {picks.map((pick) => (
                            <TaskRow
                                key={pick.id}
                                task={pick}
                                onComplete={() => complete(pick)}
                                onUncomplete={() => uncomplete(pick)}
                                href={timerFor(pick) ? focusView(timerFor(pick)!.id) : undefined}
                                trailing={
                                    timerFor(pick) ? (
                                        <RunningControls running={timerFor(pick)!} />
                                    ) : pick.done ? (
                                        pick.minutes ? formatMinutes(pick.minutes) : null
                                    ) : (
                                        <>
                                            <SoloButton
                                                size="sm"
                                                variant={timers.length === 0 && pick === open[0] ? 'primary' : 'ghost'}
                                                disabled={pending}
                                                onClick={() => start(pick.task_id)}
                                            >
                                                ▶ {t('Start')}
                                            </SoloButton>
                                            <SoloButton
                                                size="sm"
                                                variant="ghost"
                                                aria-label={t('Remove from today')}
                                                onClick={() => pick.id && router.delete(day.picks.destroy(pick.id).url, { preserveScroll: true })}
                                            >
                                                ×
                                            </SoloButton>
                                        </>
                                    )
                                }
                            />
                        ))}
                    </ul>
                </div>
                {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                {picks.length < maxPicks && (
                    <Link href={today.tasks()} className={styles.addRow}>
                        <span aria-hidden="true">+</span>
                        <span>{picks.length === 0 ? t('Pick the first task') : t('Pick another')}</span>
                    </Link>
                )}
                <OffPlanSection date={date} rows={offPlan} timers={timers} canAdopt={picks.length < maxPicks} />
                <LooseTimersSection timers={looseTimers} />
                <div className={styles.actions}>
                    <SoloButtonLink
                        href={dashboard({ query: { widok: 'gotowe' } })}
                        variant={allDone ? 'primary' : 'ghost'}
                        size="md"
                        className={allDone ? undefined : styles.flush}
                    >
                        {t('Finish the day')} →
                    </SoloButtonLink>
                </div>
            </div>
            <aside className={styles.rail}>
                <MonthCard month={month} />
            </aside>
        </main>
    );
}

function useElapsed(startedAt: string) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(id);
    }, []);
    const secs = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));

    return { h: Math.floor(secs / 3600), m: Math.floor((secs % 3600) / 60), s: secs % 60 };
}

function TimerState({ running, picks, maxPicks, closed }: TodayProps & { running: Running }) {
    const { t, i18n } = useTranslation();
    const { h, m, s } = useElapsed(running.started_at);
    const since = new Intl.DateTimeFormat(i18n.language, { hour: '2-digit', minute: '2-digit' }).format(new Date(running.started_at));
    const next = picks.find((p) => !p.done && p.task_id !== running.task_id);
    const slots = Array.from({ length: maxPicks }, (_, i) => picks[i]);
    const { pending, stop } = useTimerActions();

    return (
        <main className={styles.grid}>
            <div className={styles.head}>
                <DayRow current="today" closed={closed}>
                    <span className={styles.live}>
                        <span className={styles.dot} aria-hidden="true" />
                        {t('Timer running · since {{time}}', { time: since })}
                    </span>
                </DayRow>
                <h1 className={styles.taskTitle}>{running.task ?? t('Untitled session')}</h1>
                <Eyebrow>{[running.client, running.project].filter(Boolean).join(' · ')}</Eyebrow>
                <p className={styles.clock} aria-label={t('{{h}} h {{m}} min elapsed', { h, m })}>
                    <span className={styles.clockMain}>
                        {h}:{String(m).padStart(2, '0')}
                    </span>
                    <span className={styles.clockSecs}>:{String(s).padStart(2, '0')}</span>
                </p>
            </div>
            <div className={styles.stop}>
                <SoloButton size="lg" disabled={pending} onClick={() => stop(running.id)}>
                    ■ {t('Stop')}
                </SoloButton>
            </div>
            <footer className={styles.footer}>
                <div className={styles.footBlock}>
                    <p className={styles.caps}>{t('Today')}</p>
                    <div className={styles.segs} aria-hidden="true">
                        {slots.map((p, i) => (
                            <span
                                key={i}
                                className={[styles.seg, p?.done ? styles.segDone : '', p && p.task_id === running.task_id && !p.done ? styles.segNow : ''].join(' ')}
                            />
                        ))}
                    </div>
                    <p className={styles.quiet}>{t('{{done}} of {{count}} done', { done: picks.filter((p) => p.done).length, count: picks.length })}</p>
                </div>
                {next && (
                    <div className={[styles.footBlock, styles.footBlockWide].join(' ')}>
                        <p className={styles.caps}>{t('Next')}</p>
                        <p className={styles.footTitle}>{next.name}</p>
                        <p className={styles.quiet}>{[next.client, next.project].filter(Boolean).join(' · ')}</p>
                    </div>
                )}
            </footer>
        </main>
    );
}

function DoneState({ date, picks, month, totals, tomorrowPicks, closed, running, offPlan, timers, looseTimers }: TodayProps) {
    const { t } = useTranslation();
    const { line } = useDateLine(date);
    const done = picks.filter((p) => p.done);
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <main className={styles.grid}>
            <div className={styles.head}>
                <DayRow current="done" closed={closed}>
                    {line}
                </DayRow>
                <Poster>{closed ? t('Done.') : t('Done?')}</Poster>
            </div>
            <div className={[styles.main, styles.mainWide].join(' ')}>
                <div className={styles.stats}>
                    <div className={styles.stat}>
                        <span className={styles.statValue}>{t('{{done}} of {{count}}', { done: done.length, count: picks.length })}</span>
                        <span className={styles.statLabel}>{t('planned')}</span>
                    </div>
                    {offPlan.length > 0 && (
                        <div className={styles.stat}>
                            <span className={styles.statValue}>{offPlan.length}</span>
                            <span className={styles.statLabel}>{t('off plan')}</span>
                        </div>
                    )}
                    <div className={styles.stat}>
                        <span className={styles.statValue}>{formatMinutes(totals.logged_minutes)}</span>
                        <span className={styles.statLabel}>{t('logged')}</span>
                    </div>
                    <div className={styles.stat}>
                        <span className={styles.statValue}>{totals.clients}</span>
                        <span className={styles.statLabel}>{t('clients')}</span>
                    </div>
                </div>
                <ul className={styles.list}>
                    {picks.map((pick) => {
                        const timer = pick.running_id ? timers.find((tm) => tm.id === pick.running_id) : undefined;
                        return (
                            <TaskRow
                                key={pick.id}
                                task={pick}
                                onComplete={() => complete(pick)}
                                onUncomplete={() => uncomplete(pick)}
                                href={timer ? focusView(timer.id) : undefined}
                                trailing={timer ? <RunningControls running={timer} /> : pick.minutes ? formatMinutes(pick.minutes) : null}
                            />
                        );
                    })}
                </ul>
                <OffPlanSection date={date} rows={offPlan} timers={timers} canAdopt={false} />
                <LooseTimersSection timers={looseTimers} />
            </div>
            <aside className={styles.rail}>
                <MonthCard month={month} showLegend={false} />
                {closed ? (
                    <p className={styles.quiet}>{t('Day closed. Timers stopped, nothing left running.')}</p>
                ) : (
                    <div className={styles.closeBox}>
                        <p className={styles.quiet}>{running ? t('A timer is still running. Stop it to close the day.') : t('The day is still open.')}</p>
                        <SoloButton size="md" disabled={running !== null} onClick={() => router.post(day.close().url)}>
                            {t('Close the day')}
                        </SoloButton>
                        {errors.close && <p className={styles.error}>{errors.close}</p>}
                    </div>
                )}
                <div className={styles.closeBox}>
                    <SectionHead label={t('Tomorrow')} />
                    {tomorrowPicks > 0 ? (
                        <p className={styles.body}>{t('{{count}} planned for tomorrow.', { count: tomorrowPicks })}</p>
                    ) : null}
                    <div className={styles.actions}>
                        <SoloButtonLink href={today.plan()} size="md">
                            {tomorrowPicks > 0 ? t('Edit the plan') : t('Plan tomorrow')} →
                        </SoloButtonLink>
                        {closed && (
                            <SoloButton variant="ghost" size="sm" onClick={() => router.delete(day.reopen().url)}>
                                {t('Reopen the day')}
                            </SoloButton>
                        )}
                    </div>
                </div>
            </aside>
        </main>
    );
}
