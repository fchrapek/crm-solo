import { Chip } from '@/components/solo/chip';
import { Icon } from '@/components/ui/icon';
import type { TFunction } from 'i18next';
import { monthTileLabel } from '@/components/solo/month-card';
import { SoloButton, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { Row, rowParts } from '@/components/solo/row';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileActions, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { capitalize, dayMonth, durationText, monthName, parseDay, shortDate, statDuration, weekdayName } from '@/lib/dates';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { focusView, StopButton, useTaskCheck } from './day';
import { DayHeading } from './day-header';
import { dayAfter } from './pick-menu';
import { dayHref, dayLabel } from './place';
import styles from './day.module.css';
import { type Overdue, type Running, type TodayProps } from './index';

const keep = { preserveScroll: true };

export interface ClientTime {
    client: string | null;
    minutes: number;
}

/**
 * Today's logged minutes per client, from the rows the page already holds, most first. Null when those rows do not
 * add up to the day's total (time with no task, or on a task the page does not list): a partial split would mislead.
 */
export function timeByClient(rows: Pick<DayTask, 'client' | 'minutes'>[], total: number): ClientTime[] | null {
    const sums = new Map<string | null, number>();
    for (const row of rows) {
        if (row.minutes) sums.set(row.client, (sums.get(row.client) ?? 0) + row.minutes);
    }
    const split = [...sums].map(([client, minutes]) => ({ client, minutes })).sort((a, b) => b.minutes - a.minutes);

    return split.length > 0 && split.reduce((sum, row) => sum + row.minutes, 0) === total ? split : null;
}

/** The one line under Tomorrow: tomorrow's plan once there is one, else what is overdue, else that nothing is planned. */
/** "na jutro", or "na czw. 19.11" for a day further on. */
export function movedTo(to: string, date: string, locale: string, t: TFunction): string {
    return to === dayAfter(date)
        ? t('to tomorrow')
        : t('to {{day}}', { day: `${new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(parseDay(to))} ${shortDate(to, locale)}` });
}

export function TomorrowLine({ tomorrowPicks, overdue }: { tomorrowPicks: number; overdue: Overdue | null }) {
    const { t, i18n } = useTranslation();

    if (tomorrowPicks > 0) return <TileNote className={styles.line}>{t('{{count}} planned for tomorrow.', { count: tomorrowPicks })}</TileNote>;
    if (overdue !== null && overdue.count > 0 && overdue.oldest) {
        return (
            <TileNote className={styles.line}>
                {t('{{count}} overdue left, the oldest since {{date}}.', {
                    count: overdue.count,
                    date: dayMonth(parseDay(overdue.oldest), i18n.language),
                })}
            </TileNote>
        );
    }

    return <TileNote className={styles.line}>{t('Nothing planned for tomorrow yet.')}</TileNote>;
}

/** The day's picks as the summary lists them: the tick, the title, the time of a done one, the client from 768 px; a running timer keeps its Stop. */
export function PlanRows({ picks, timers }: { picks: DayTask[]; timers: Running[] }) {
    const { t } = useTranslation();
    const check = useTaskCheck();

    return (
        <TileRows>
            {picks.map((pick) => {
                const timer = pick.running_id ? timers.find((entry) => entry.id === pick.running_id) : undefined;

                return (
                    <Row
                        key={pick.id}
                        check={check(pick, () => pick.id && router.post(day.picks.complete(pick.id).url, {}, keep))}
                        title={pick.name}
                        href={timer ? focusView(timer.id) : tasks.show(pick.task_id)}
                        running={timer?.started_at}
                        time={pick.done && pick.minutes ? durationText(pick.minutes, t) : undefined}
                        wideMeta={pick.client}
                        action={timer ? <StopButton timerId={timer.id} /> : undefined}
                    />
                );
            })}
        </TileRows>
    );
}

/** Whether the day is closed, beside the close button: a chip, or the reason the close is refused while a timer runs. */
export function DayStatus({ closed, running }: { closed: boolean; running: boolean }) {
    const { t } = useTranslation();

    if (closed) return <Chip status="done">{t('Day closed.')}</Chip>;
    if (running) return <span className={styles.statusLine}>{t('A timer is still running. Stop it to close the day.')}</span>;

    return <Chip status="open">{t('The day is still open.')}</Chip>;
}

/**
 * Figma: Summary (1091:14556, phone 1091:14778; closed 1091:15438; blocked by a timer 1091:15874). Four number
 * tiles, the day's plan with what was done off it, the close, the time per client and tomorrow.
 */
export function SummaryView({ date, picks, month, totals, tomorrowPicks, overdue, closed, offPlan, moved, timers, looseTimers, day: nav }: TodayProps) {
    const { t, i18n } = useTranslation();
    const locale = i18n.language;
    const errors = usePage().props.errors as Record<string, string>;
    const check = useTaskCheck();
    const done = picks.filter((pick) => pick.done).length;
    const running = timers.length > 0;
    const offMinutes = offPlan.reduce((sum, row) => sum + (row.minutes ?? 0), 0);
    const movedMinutes = moved.reduce((sum, row) => sum + (row.minutes ?? 0), 0);
    const movedNote = moved.length > 0 ? ` · ${t('{{count}} moved', { count: moved.length })}` : '';
    const byClient = timeByClient([...picks, ...offPlan, ...moved], totals.logged_minutes);
    const d = parseDay(date);
    const tomorrow = weekdayName(new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, 12), locale);
    const doneOf = t('{{done}} of {{count}}', { done, count: picks.length });

    return (
        <main className={styles.summary}>
            <DayHeading title={closed ? t('Done.') : t('Done?')} />
            <div className={styles.stats}>
                <Tile kind="plain" as="div">
                    <Stat kind="tile" label={closed ? t('tasks_stat') : t('planned')} value={doneOf} sub={`${t('done_stat')}${movedNote}`} />
                </Tile>
                <Tile kind="plain" as="div">
                    <Stat
                        kind="tile"
                        label={t('logged')}
                        value={statDuration(totals.logged_minutes, t)}
                        sub={nav ? t('that day') : running ? t('without the running timer') : t('today')}
                    />
                </Tile>
                <Tile kind="plain" as="div">
                    <Stat kind="tile" label={t('Clients')} value={totals.clients} sub={nav ? t('with time that day') : t('with time today')} />
                </Tile>
                <Tile kind="plain" as="div">
                    <Stat
                        kind="tile"
                        label={monthTileLabel(capitalize(monthName(month.month, locale)), locale, date)}
                        value={t('{{done}} of {{count}}', { done: month.closed, count: month.weekdays })}
                        sub={t('days closed')}
                    />
                </Tile>
            </div>
            <TileGrid>
                <Tile kind="list">
                    <GroupHeading label={t('Plan of the day')} end={`${t('{{done}} of {{count}} done', { done, count: picks.length })}${movedNote}`} />
                    {picks.length > 0 ? (
                        <PlanRows picks={picks} timers={timers} />
                    ) : (
                        <TileInset>
                            <TileNote>{t('Nothing was picked for today.')}</TileNote>
                        </TileInset>
                    )}
                    {moved.length > 0 && (
                        <>
                            <GroupHeading label={t('Moved')} end={movedMinutes > 0 ? durationText(movedMinutes, t) : undefined} />
                            <TileRows>
                                {moved.map((row) => (
                                    <Row
                                        key={row.task_id}
                                        title={
                                            <span className={styles.movedTitle}>
                                                <Icon name="arrow-right" size={20} />
                                                {row.name}
                                            </span>
                                        }
                                        href={tasks.show(row.task_id)}
                                        time={row.minutes ? durationText(row.minutes, t) : undefined}
                                        meta={movedTo(row.moved_to, date, locale, t)}
                                    />
                                ))}
                            </TileRows>
                        </>
                    )}
                    {offPlan.length > 0 && (
                        <>
                            <GroupHeading label={t('Off plan')} end={offMinutes > 0 ? durationText(offMinutes, t) : undefined} />
                            <TileRows>
                                {offPlan.map((row) => {
                                    const timer = row.running_id ? timers.find((entry) => entry.id === row.running_id) : undefined;

                                    return (
                                        <Row
                                            key={row.task_id}
                                            check={check(row, () => router.post(day.tasks.complete(row.task_id).url, {}, keep))}
                                            title={row.name}
                                            href={timer ? focusView(timer.id) : tasks.show(row.task_id)}
                                            running={timer?.started_at}
                                            time={row.minutes ? durationText(row.minutes, t) : undefined}
                                            wideMeta={row.client}
                                            action={timer ? <StopButton timerId={timer.id} /> : undefined}
                                        />
                                    );
                                })}
                            </TileRows>
                        </>
                    )}
                    {looseTimers.length > 0 && (
                        <>
                            <GroupHeading label={t('Other running timers')} note={looseTimers.length} />
                            <TileRows>
                                {looseTimers.map((timer) => (
                                    <Row
                                        key={timer.id}
                                        title={timer.task ?? t('Untitled session')}
                                        href={focusView(timer.id)}
                                        running={timer.started_at}
                                        wideMeta={rowParts([timer.client, timer.project])}
                                        action={<StopButton timerId={timer.id} />}
                                    />
                                ))}
                            </TileRows>
                        </>
                    )}
                    <TileActions apart className={styles.close}>
                        {!closed && (
                            <SoloButton size="control" disabled={running} onClick={() => router.post(day.close().url, nav ? { date } : {})}>
                                {t('Close the day')}
                            </SoloButton>
                        )}
                        <span className={styles.closeStatus}>
                            <DayStatus closed={closed} running={running} />
                        </span>
                        {closed && (
                            <SoloButton variant="quiet" size="control" onClick={() => router.delete(day.reopen().url, { data: nav ? { date } : {} })}>
                                {t('Reopen the day')}
                            </SoloButton>
                        )}
                    </TileActions>
                    {errors.close && (
                        <TileInset>
                            <p className={styles.error}>{errors.close}</p>
                        </TileInset>
                    )}
                </Tile>
                <TileStack as="aside">
                    {byClient !== null && (
                        <Tile kind="list">
                            <TileHeader label={t('Time by client')} end={statDuration(totals.logged_minutes, t)} />
                            <TileRows>
                                {byClient.map((row) => (
                                    <Row key={row.client ?? ''} title={row.client ?? t('No client')} time={durationText(row.minutes, t)} />
                                ))}
                            </TileRows>
                        </Tile>
                    )}
                    {nav ? (
                        <Tile kind="plain">
                            <TileHeader label={t('Next day')} end={dayLabel(nav.next, locale)} />
                            <TileNote>{t('Planned for {{day}}: {{count}}.', { day: dayLabel(nav.next, locale), count: tomorrowPicks })}</TileNote>
                            <SoloButtonLink href={dayHref(nav.next, nav.today)} variant="primary" size="control" className={styles.planButton}>
                                {t('Show the day')}
                            </SoloButtonLink>
                        </Tile>
                    ) : (
                        <Tile kind="plain">
                            <TileHeader label={t('Tomorrow')} end={tomorrow} />
                            <TomorrowLine tomorrowPicks={tomorrowPicks} overdue={overdue} />
                            <SoloButtonLink href={today.plan()} variant={closed ? 'primary' : 'secondary'} size="control" className={styles.planButton}>
                                {t('Plan for tomorrow')}
                            </SoloButtonLink>
                        </Tile>
                    )}
                </TileStack>
            </TileGrid>
        </main>
    );
}
