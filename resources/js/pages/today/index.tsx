import { BottomAction } from '@/components/solo/bottom-action';
import { DayTrail } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { MonthCard, type MonthData } from '@/components/solo/month-card';
import { Poster, SectionHead, SoloButton, SoloButtonLink, Stat, StatRow, touchTargetClass } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface, type DayState } from '@/components/solo/surface';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { SoloToaster } from '@/components/solo/toaster';
import { Icon } from '@/components/ui/icon';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { dayMonth, durationText, fromDay, hoursClock, parseDay, runningMinutesToday, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './today.module.css';

interface Running {
    id: number;
    started_at: string;
    task_id: number | null;
    task: string | null;
    client: string | null;
    project: string | null;
}

export interface Overdue {
    count: number;
    oldest: string | null;
}

interface TodayProps {
    state: DayState;
    closed: boolean;
    date: string;
    /** The UTC instant the display calendar's day began. */
    dayStart: string;
    maxPicks: number;
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
    const { t } = useTranslation();
    // Every prop follows from the date and the running timers, so the whole page reloads.
    useLiveReload();

    return (
        <SoloSurface className={styles.page} day={props.state}>
            <Head title={t('Today')} />
            <div className={styles.frame}>
                <Masthead reversed={props.state !== 'paper'} />
                {props.state === 'timer' && props.running ? <TimerState {...props} running={props.running} /> : null}
                {props.state === 'done' ? <DoneState {...props} /> : null}
                {props.state === 'paper' ? <PaperState {...props} /> : null}
            </div>
            <SoloToaster />
        </SoloSurface>
    );
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

const focusView = (timerId: number) => dashboard({ query: { widok: 'timer', timer: timerId } });

/** Figma: the running row on the default Dziś screen. Live dot, elapsed time, Stop; on phones the clock above Stop. */
function RunningControls({ running }: { running: Running }) {
    const { t } = useTranslation();
    const { h, m } = useElapsed(running.started_at);
    const { pending, stop } = useTimerActions();

    return (
        <span className={styles.running} data-running="">
            <span className={styles.liveDot} aria-hidden="true" />
            <span className={styles.elapsed} aria-label={t('{{h}} h {{m}} min elapsed', { h, m })}>
                {h}:{String(m).padStart(2, '0')}
            </span>
            <SoloButton variant="stop" size="sm" disabled={pending} onClick={() => stop(running.id)}>
                {t('Stop')}
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
    const meta = [live > 0 ? t('{{count}} running', { count: live }) : null, logged > 0 ? durationText(logged, t) : null].filter(Boolean).join(' · ');

    return (
        <div className={styles.card}>
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
                                    {timer ? <RunningControls running={timer} /> : row.minutes ? durationText(row.minutes, t) : null}
                                    {!row.done && canAdopt && (
                                        <SoloButton
                                            size="sm"
                                            variant="ghost"
                                            className={touchTargetClass}
                                            onClick={() =>
                                                router.post(day.picks.store().url, { task_id: row.task_id, date }, { preserveScroll: true })
                                            }
                                        >
                                            {t('To the plan')}
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

/**
 * Figma: Dziś, Z poprzednich dni (922:21158). An earlier day's unfinished pick waits here: Na dziś takes it into
 * today, the cross dismisses it; nothing moves on its own.
 */
function CarriedSection({ date, rows, canTake }: { date: string; rows: TodayProps['carried']; canTake: boolean }) {
    const { t, i18n } = useTranslation();
    if (rows.length === 0) return null;

    return (
        <div className={styles.card}>
            <SectionHead label={t('From earlier days')} meta={t('Nothing moves on its own')} quiet />
            <ul className={styles.list}>
                {rows.map((row) => (
                    <TaskRow
                        key={row.id}
                        task={row}
                        trailing={
                            <>
                                <span className={styles.late}>{fromDay(row.from, date, i18n.language, t)}</span>
                                <SoloButton
                                    size="sm"
                                    variant="ghost"
                                    className={touchTargetClass}
                                    disabled={!canTake}
                                    onClick={() => router.post(day.picks.store().url, { task_id: row.task_id, date }, { preserveScroll: true })}
                                >
                                    {t('For today')}
                                </SoloButton>
                                <SoloButton
                                    size="sm"
                                    variant="ghost"
                                    className={touchTargetClass}
                                    aria-label={`${t('Remove')}: ${row.name}`}
                                    title={t('Remove')}
                                    onClick={() => router.delete(day.picks.dismiss(row.id).url, { preserveScroll: true })}
                                >
                                    <Icon name="close" size={16} />
                                </SoloButton>
                            </>
                        }
                    />
                ))}
            </ul>
        </div>
    );
}

/** Every running timer stays visible and stoppable; these are the ones no task row carries. */
function LooseTimersSection({ timers }: { timers: Running[] }) {
    const { t } = useTranslation();
    if (timers.length === 0) return null;

    return (
        <div className={styles.card}>
            <SectionHead label={t('Other running timers')} meta={t('{{count}} running', { count: timers.length })} quiet />
            <ul className={styles.list}>
                {timers.map((timer) => (
                    <TaskRow
                        key={timer.id}
                        task={{
                            task_id: timer.task_id ?? 0,
                            name: timer.task ?? t('Untitled session'),
                            client: timer.client,
                            project: timer.project,
                            done: false,
                        }}
                        href={focusView(timer.id)}
                        trailing={<RunningControls running={timer} />}
                    />
                ))}
            </ul>
        </div>
    );
}

export function PickActions({ main, pending, onStart, onRemove }: { main: boolean; pending: boolean; onStart: () => void; onRemove: () => void }) {
    const { t } = useTranslation();

    return (
        <>
            <SoloButton size="sm" variant={main ? 'primary' : 'ghost'} className={touchTargetClass} disabled={pending} onClick={onStart}>
                <Icon name="play" size={16} filled />
                {t('Start')}
            </SoloButton>
            <SoloButton size="sm" variant="ghost" className={touchTargetClass} aria-label={t('Remove from today')} title={t('Remove from today')} onClick={onRemove}>
                <Icon name="close" size={16} />
            </SoloButton>
        </>
    );
}

function PaperState({ date, picks, maxPicks, month, closed, timers, offPlan, looseTimers, carried }: TodayProps) {
    const { t, i18n } = useTranslation();
    const errors = usePage().props.errors as Record<string, string>;
    const { pending, start } = useTimerActions();
    const open = picks.filter((p) => !p.done);
    const allDone = picks.length > 0 && open.length === 0;
    const timerFor = (pick: DayTask) => (pick.running_id ? timers.find((tm) => tm.id === pick.running_id) : undefined);

    return (
        <main className={`${splitClass} ${styles.grid}`}>
            <div className={`${splitMainClass} ${styles.main}`}>
                <div className={styles.head}>
                    <DayTrail current="today" closed={closed} />
                    <Poster>{weekdayName(parseDay(date), i18n.language)}</Poster>
                </div>
                <div className={styles.card}>
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
                                        pick.minutes ? (
                                            durationText(pick.minutes, t)
                                        ) : null
                                    ) : (
                                        <PickActions
                                            main={timers.length === 0 && pick === open[0]}
                                            pending={pending}
                                            onStart={() => start(pick.task_id)}
                                            onRemove={() => pick.id && router.delete(day.picks.destroy(pick.id).url, { preserveScroll: true })}
                                        />
                                    )
                                }
                            />
                        ))}
                        {picks.length < maxPicks && (
                            <li className={styles.addItem}>
                                <Link href={today.tasks()} className={`${styles.addRow} ${touchTargetClass}`}>
                                    {picks.length === 0 ? t('Pick the first task') : t('Pick another')}
                                </Link>
                            </li>
                        )}
                    </ul>
                </div>
                {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                {picks.length < maxPicks && (
                    <Link href={today.tasks()} className={`${styles.addRow} ${styles.addBelow} ${touchTargetClass}`}>
                        {picks.length === 0 ? t('Pick the first task') : t('Pick another')}
                    </Link>
                )}
                <CarriedSection date={date} rows={carried} canTake={picks.length < maxPicks} />
                <OffPlanSection date={date} rows={offPlan} timers={timers} canAdopt={picks.length < maxPicks} />
                <LooseTimersSection timers={looseTimers} />
                {/* desktop reaches Podsumowanie through the trail beside the poster */}
                <div className={`${styles.actions} ${styles.phoneOnly}`}>
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
            <aside className={`${splitSideClass} ${styles.rail} ${styles.wideRail}`}>
                <MonthCard month={month} date={date} monthLink />
            </aside>
        </main>
    );
}

function useElapsed(startedAt: string) {
    const now = useNow(1000) ?? Date.parse(startedAt);
    const secs = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));

    return { h: Math.floor(secs / 3600), m: Math.floor((secs % 3600) / 60), s: secs % 60 };
}

/** Minutes logged today plus the running timers that started today, from the display calendar's midnight the server sends. */
function useTodayTotal(dayStart: string, loggedMinutes: number, timers: Running[]): number {
    const now = useNow(15_000);
    if (now === null) return loggedMinutes;

    return loggedMinutes + runningMinutesToday(dayStart, timers.map((timer) => timer.started_at), now);
}

/**
 * Figma: Timer A2, the side column. The day's picks as Queue rows: done with its duration, the running one "w toku"
 * on the tint, the next open one "następne", the rest bare.
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
                            onComplete={() => complete(pick)}
                            onUncomplete={() => uncomplete(pick)}
                            trailing={status}
                        />
                    );
                })}
            </ul>
        </section>
    );
}

/** Figma: Timer A2 (794:58392, phone 849:20902). The task and its clock in the main column; the day's queue, the total and Stop beside it. */
function TimerState({ running, picks, closed, dayStart, totals, timers }: TodayProps & { running: Running }) {
    const { t, i18n } = useTranslation();
    const { h, m, s } = useElapsed(running.started_at);
    const since = new Intl.DateTimeFormat(i18n.language, { hour: '2-digit', minute: '2-digit' }).format(new Date(running.started_at));
    const total = useTodayTotal(dayStart, totals.logged_minutes, timers);
    const where = [running.client, running.project].filter(Boolean).join(' · ');
    const { pending, stop } = useTimerActions();

    return (
        <main className={`${splitClass} ${styles.grid}`}>
            <div className={`${splitMainClass} ${styles.timerMain}`}>
                <DayTrail current="today" closed={closed} />
                <div className={styles.timerTask}>
                    <h1 className={styles.taskTitle}>{running.task ?? t('Untitled session')}</h1>
                    {where && <p className={styles.where}>{where}</p>}
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
                <div className={`${styles.actions} ${styles.barSlot}`}>
                    <BottomAction>
                        <SoloButton variant="stop" size="md" disabled={pending} onClick={() => stop(running.id)}>
                            {t('Stop')}
                        </SoloButton>
                    </BottomAction>
                </div>
            </aside>
        </main>
    );
}

/** The one line under JUTRO: tomorrow's plan once there is one, else what is overdue, else that nothing is planned. */
export function TomorrowLine({ tomorrowPicks, overdue }: { tomorrowPicks: number; overdue: Overdue | null }) {
    const { t, i18n } = useTranslation();

    if (tomorrowPicks > 0) return <p className={styles.body}>{t('{{count}} planned for tomorrow.', { count: tomorrowPicks })}</p>;
    if (overdue !== null && overdue.count > 0 && overdue.oldest) {
        return (
            <p className={styles.body}>
                {t('{{count}} overdue left, the oldest since {{date}}.', {
                    count: overdue.count,
                    date: dayMonth(parseDay(overdue.oldest), i18n.language),
                })}
            </p>
        );
    }

    return <p className={styles.body}>{t('Nothing planned for tomorrow yet.')}</p>;
}

/** Figma: Done row in the accent block: the title and its time; an unfinished pick keeps an open box and shows no time. */
export function DoneList({ picks, timers }: { picks: DayTask[]; timers: Running[] }) {
    const { t } = useTranslation();

    return (
        <ul className={styles.list}>
            {picks.map((pick) => {
                const timer = pick.running_id ? timers.find((tm) => tm.id === pick.running_id) : undefined;
                return (
                    <TaskRow
                        key={pick.id}
                        kind="done"
                        task={pick}
                        onComplete={() => complete(pick)}
                        onUncomplete={() => uncomplete(pick)}
                        href={timer ? focusView(timer.id) : undefined}
                        trailing={timer ? <RunningControls running={timer} /> : pick.done && pick.minutes ? hoursClock(pick.minutes, t) : null}
                    />
                );
            })}
        </ul>
    );
}

function DoneState({ date, picks, month, totals, tomorrowPicks, overdue, closed, running, offPlan, timers, looseTimers }: TodayProps) {
    const { t, i18n } = useTranslation();
    const done = picks.filter((p) => p.done);
    const errors = usePage().props.errors as Record<string, string>;
    const planLabel = tomorrowPicks > 0 ? t('Edit the plan') : t('Plan tomorrow');
    const d = parseDay(date);
    const tomorrow = new Intl.DateTimeFormat(i18n.language, { weekday: 'long' }).format(new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, 12));

    return (
        <main className={`${splitClass} ${styles.grid}`}>
            <div className={`${splitMainClass} ${styles.main}`}>
                <div className={`${styles.head} ${styles.doneHead}`}>
                    <DayTrail current="done" closed={closed} />
                    <Poster size="display">{closed ? t('Done.') : t('Done?')}</Poster>
                </div>
                <StatRow fill>
                    <Stat
                        value={t('{{done}} of {{count}}', { done: done.length, count: picks.length })}
                        label={closed ? t('tasks_stat') : t('planned')}
                    />
                    <Stat value={hoursClock(totals.logged_minutes, t)} label={t('logged')} />
                    <Stat value={totals.clients} label={t('clients')} />
                </StatRow>
                <DoneList picks={picks} timers={timers} />
                <OffPlanSection date={date} rows={offPlan} timers={timers} canAdopt={false} />
                <LooseTimersSection timers={looseTimers} />
            </div>
            <aside className={`${splitSideClass} ${styles.rail} ${styles.summaryRail}`}>
                <MonthCard month={month} />
                <div className={styles.status}>
                    <DayStatus closed={closed} running={running !== null} />
                    {!closed && (
                        <div className={`${styles.actions} ${styles.barSlot}`}>
                            <BottomAction>
                                <SoloButton size="md" disabled={running !== null} onClick={() => router.post(day.close().url)}>
                                    {t('Close the day')}
                                </SoloButton>
                            </BottomAction>
                        </div>
                    )}
                    {errors.close && <p className={styles.error}>{errors.close}</p>}
                </div>
                <section className={styles.tomorrow}>
                    <SectionHead label={t('Tomorrow')} meta={tomorrow} inset="none" className={styles.headTight} />
                    <TomorrowLine tomorrowPicks={tomorrowPicks} overdue={overdue} />
                    {closed ? (
                        <div className={`${styles.actions} ${styles.barSlot}`}>
                            <BottomAction
                                secondary={
                                    <SoloButton variant="text" size="sm" onClick={() => router.delete(day.reopen().url)}>
                                        {t('Reopen the day')}
                                    </SoloButton>
                                }
                            >
                                <SoloButtonLink href={today.plan()} size="md">
                                    {planLabel} →
                                </SoloButtonLink>
                            </BottomAction>
                        </div>
                    ) : (
                        <SoloButtonLink href={today.plan()} size="md">
                            {planLabel} →
                        </SoloButtonLink>
                    )}
                </section>
            </aside>
        </main>
    );
}

/** Figma: A2f / Day status. Whether the day is closed, under the calendar. */
export function DayStatus({ closed, running }: { closed: boolean; running: boolean }) {
    const { t } = useTranslation();

    return (
        <p className={styles.dayStatus}>
            <Icon name={closed ? 'done' : 'circle'} size={16} filled={closed} />
            {closed ? t('Day closed.') : running ? t('A timer is still running. Stop it to close the day.') : t('The day is still open.')}
        </p>
    );
}
