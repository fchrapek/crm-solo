import { ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { IconButton } from '@/components/solo/icon-button';
import { SoloButton, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { listDetails, type RowDetail, Row, rowParts } from '@/components/solo/row';
import { Elapsed } from '@/components/solo/elapsed';
import { RowMenu } from '@/components/solo/row-menu';
import { TimerSwitch } from '@/components/solo/shell/timer-switch';
import { type DayTask } from '@/components/solo/task-row';
import { FoldingGroup, GroupHeading, Tile, TileActions, TileGrid, TileInset, TileRows, TileStack } from '@/components/solo/tile';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { durationText, parseDay, shortDate, weekdayDate, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { router, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { AddOrPick } from './add-or-pick';
import { DayHeading, DayMonth } from './day-header';
import styles from './day.module.css';
import { type Running, type TodayProps } from './index';
import { PickMenu } from './pick-menu';

export const focusView = (timerId: number) => dashboard({ query: { view: 'timer', timer: timerId } });
const keep = { preserveScroll: true };

/** Figma: Today rows (1165:70988). Start and stop of a row's timer as icons; every other row button stays text. */
export function RowStart({ taskId, name }: { taskId: number; name: string }) {
    const { t } = useTranslation();
    const { pending, start } = useTimerActions();

    return <IconButton icon="play" look="outline" size="row" filled label={`${t('Start')}: ${name}`} disabled={pending} onClick={() => start(taskId)} />;
}

export function RowStop({ timerId, name }: { timerId: number; name: string }) {
    const { t } = useTranslation();
    const { pending, stop } = useTimerActions();

    return <IconButton icon="stop" look="stop" size="row" filled label={`${t('Stop')}: ${name}`} disabled={pending} onClick={() => stop(timerId)} />;
}

export function StopButton({ timerId, onColour = false }: { timerId: number; onColour?: boolean }) {
    const { t } = useTranslation();
    const { pending, stop } = useTimerActions();

    return (
        <SoloButton variant={onColour ? 'on-colour' : 'stop'} size="control" disabled={pending} onClick={() => stop(timerId)}>
            {t('Stop')}
        </SoloButton>
    );
}

/** The add row's quiet end meta: the free places, "day full" at the limit, and past it how many over the plan (when the warning is on). */
export function placesNote(picked: number, max: number, warn: boolean, t: TFunction): string {
    if (picked < max) return t('{{count}} free slots', { count: max - picked });

    return picked > max && warn ? t('{{count}} over the plan', { count: picked - max }) : t('day full');
}

/** A task row's details: where it belongs, its due day and the time logged on it. */
export function taskDetails(task: Pick<DayTask, 'client' | 'project' | 'due' | 'logged_minutes'>, t: TFunction, locale: string, from?: string): RowDetail[] {
    return [
        { label: t('detail_client'), value: task.client },
        { label: t('Project'), value: task.project },
        { label: t('Due'), value: task.due ? shortDate(task.due, locale) : null, short: true },
        ...(from === undefined ? [] : [{ label: t('From day'), value: from, short: true }]),
        { label: t('Time'), value: task.logged_minutes ? durationText(task.logged_minutes, t) : null, short: true },
    ];
}

/** The running timer a task row shows, among the account's running timers. */
export const timerOf = <T extends { id: number }>(row: Pick<DayTask, 'running_id'>, timers: T[]): T | undefined =>
    row.running_id ? timers.find((timer) => timer.id === row.running_id) : undefined;

/** The tick of a task row: a done task unticks only when the CRM set the tick. */
export function useTaskCheck() {
    const { t } = useTranslation();

    return (task: DayTask, onComplete: () => void) => {
        const untick = task.done && task.can_untick ? () => router.delete(day.tasks.uncomplete(task.task_id).url, keep) : undefined;

        return {
            done: task.done,
            progress: task.in_progress,
            label: `${task.done ? (untick ? t('Mark as not done') : t('Done')) : t('Mark as done')}: ${task.name}`,
            onToggle: task.done ? untick : onComplete,
        };
    };
}

/** Figma: Tile / timer. The running timer beside the day from 768 px; a phone has the row and the strip, never a third. */
export function TimerTile({
    running,
    timers = [],
}: {
    running: Pick<Running, 'id' | 'started_at' | 'task' | 'client'>;
    timers?: Pick<Running, 'id' | 'started_at' | 'task' | 'client'>[];
}) {
    const { t } = useTranslation();
    const what = [running.task ?? t('Untitled session'), running.client].filter(Boolean).join(' · ');

    return (
        <Tile kind="timer" className={styles.timerTile} aria-label={t('Timer running')}>
            <div className={styles.timerSwitch}>
                <TimerSwitch timers={timers} shown={running.id} />
            </div>
            <Stat kind="tile" tone="on-colour" label={t('Timer running')} value={<Elapsed startedAt={running.started_at} />} sub={what} />
            <StopButton timerId={running.id} onColour />
        </Tile>
    );
}

/**
 * Figma: Today (1075:578, phone 1075:780). The day's tile: the weekday, the picks with their tick and timer, the next
 * pick, what waits from earlier days, work off the plan, the close and tomorrow; beside it the timer and the month.
 */
export function DayView({
    date,
    picks,
    maxPicks,
    warnFull,
    moveDays,
    month,
    closed,
    timers,
    running,
    offPlan,
    looseTimers,
    carried,
}: TodayProps) {
    const { t, i18n } = useTranslation();
    const errors = usePage().props.errors as Record<string, string>;
    const check = useTaskCheck();
    const pick = (taskId: number) => router.post(day.picks.store().url, { task_id: taskId, date }, keep);
    const [pickDetails, offPlanDetails] = [picks, offPlan].map((rows) => listDetails(rows.map((row) => taskDetails(row, t, i18n.language))));
    const thisYear = parseDay(date).getFullYear();
    const carriedDetails = listDetails(carried.map((row) => taskDetails(row, t, i18n.language, weekdayDate(row.from, i18n.language, thisYear))));

    return (
        <main>
            <TileGrid>
                <section className={styles.day}>
                    <DayHeading inTile title={weekdayName(parseDay(date), i18n.language)} />
                    <Tile kind="list" as="div" className={styles.dayList}>
                        <TileRows className={styles.picks}>
                            {picks.map((row, index) => {
                                const timer = timerOf(row, timers);

                                return (
                                    <Row
                                        key={row.id}
                                        check={check(row, () => row.id && router.post(day.picks.complete(row.id).url, {}, keep))}
                                        title={row.name}
                                        href={timer ? focusView(timer.id) : tasks.show(row.task_id)}
                                        running={timer?.started_at}
                                        time={row.minutes ? durationText(row.minutes, t) : undefined}
                                        timeTone="quiet"
                                        details={pickDetails[index]}
                                        action={
                                            timer ? (
                                                <RowStop timerId={timer.id} name={row.name} />
                                            ) : row.done ? undefined : (
                                                <RowStart taskId={row.task_id} name={row.name} />
                                            )
                                        }
                                        more={
                                            timer || row.done || row.id === undefined ? undefined : (
                                                <PickMenu
                                                    pickId={row.id}
                                                    taskId={row.task_id}
                                                    name={row.name}
                                                    date={date}
                                                    days={moveDays}
                                                    max={maxPicks}
                                                />
                                            )
                                        }
                                    />
                                );
                            })}
                            {closed ? (
                                <Row
                                    title={
                                        <span className={styles.quietTitle}>
                                            {t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks })}
                                        </span>
                                    }
                                />
                            ) : (
                                <AddOrPick
                                    date={date}
                                    picked={picks.length}
                                    maxPicks={maxPicks}
                                    warnFull={warnFull}
                                    listHref={today.tasks().url}
                                    note={placesNote(picks.length, maxPicks, warnFull, t)}
                                />
                            )}
                        </TileRows>
                        {errors.task_id && (
                            <TileInset>
                                <p className={styles.error}>{errors.task_id}</p>
                            </TileInset>
                        )}
                        {carried.length > 0 && (
                            <FoldingGroup label={t('From earlier days')} note={carried.length}>
                                <TileRows>
                                    {carried.map((row, index) => (
                                        <Row
                                            key={row.id}
                                            title={row.name}
                                            href={tasks.show(row.task_id)}
                                            details={carriedDetails[index]}
                                            action={
                                                <SoloButton variant="quiet" size="control" onClick={() => pick(row.task_id)}>
                                                    {t('For today')}
                                                </SoloButton>
                                            }
                                            more={
                                                <RowMenu name={row.name}>
                                                    <ActionMenuItem onSelect={() => router.delete(day.picks.dismiss(row.id).url, keep)}>
                                                        {t('Remove')}
                                                    </ActionMenuItem>
                                                </RowMenu>
                                            }
                                        />
                                    ))}
                                </TileRows>
                            </FoldingGroup>
                        )}
                        {offPlan.length > 0 && (
                            <>
                                <GroupHeading label={t('Off plan')} note={offPlan.length} />
                                <TileRows>
                                    {offPlan.map((row, index) => {
                                        const timer = timerOf(row, timers);

                                        return (
                                            <Row
                                                key={row.task_id}
                                                check={check(row, () => router.post(day.tasks.complete(row.task_id).url, {}, keep))}
                                                title={row.name}
                                                href={timer ? focusView(timer.id) : tasks.show(row.task_id)}
                                                running={timer?.started_at}
                                                time={row.minutes ? durationText(row.minutes, t) : undefined}
                                                timeTone="quiet"
                                                details={offPlanDetails[index]}
                                                action={timer ? <RowStop timerId={timer.id} name={row.name} /> : undefined}
                                                more={
                                                    !row.done ? (
                                                        <RowMenu name={row.name}>
                                                            <ActionMenuItem onSelect={() => pick(row.task_id)}>{t('To the plan')}</ActionMenuItem>
                                                        </RowMenu>
                                                    ) : undefined
                                                }
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
                                            action={<RowStop timerId={timer.id} name={timer.task ?? t('Untitled session')} />}
                                        />
                                    ))}
                                </TileRows>
                            </>
                        )}
                        <TileActions apart>
                            {closed ? (
                                <span className={styles.closed}>
                                    <Chip status="done">{t('Day closed')}</Chip>
                                </span>
                            ) : (
                                <SoloButton
                                    size="control"
                                    disabled={timers.length > 0}
                                    title={timers.length > 0 ? t('A timer is still running. Stop it to close the day.') : undefined}
                                    onClick={() => router.post(day.close().url)}
                                >
                                    {t('Close the day')}
                                </SoloButton>
                            )}
                            <SoloButtonLink href={today.plan()} variant="secondary" size="control">
                                {t('Plan for tomorrow')}
                            </SoloButtonLink>
                        </TileActions>
                        {errors.close && (
                            <TileInset>
                                <p className={styles.error}>{errors.close}</p>
                            </TileInset>
                        )}
                    </Tile>
                </section>
                <TileStack as="aside">
                    {running && <TimerTile running={running} timers={timers} />}
                    <DayMonth month={month} date={date} />
                </TileStack>
            </TileGrid>
        </main>
    );
}
