import { ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { MonthCard } from '@/components/solo/month-card';
import { Poster, SoloButton, SoloButtonLink, Stat } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileActions, TileGrid, TileInset, TileRows, TileStack } from '@/components/solo/tile';
import { elapsedClock, useElapsed } from '@/hooks/use-elapsed';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { durationText, fromDay, longDate, parseDay, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './day.module.css';
import { type Running, type TodayProps } from './index';

export const focusView = (timerId: number) => dashboard({ query: { widok: 'timer', timer: timerId } });
const keep = { preserveScroll: true };

/** A running timer's time, read out as hours and minutes. */
export function Elapsed({ startedAt }: { startedAt: string }) {
    const { t } = useTranslation();
    const elapsed = useElapsed(startedAt);

    return <span aria-label={t('{{h}} h {{m}} min elapsed', { h: elapsed.h, m: elapsed.m })}>{elapsedClock(elapsed)}</span>;
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

/** The tick of a task row: a done task unticks only when the CRM set the tick. */
export function useTaskCheck() {
    const { t } = useTranslation();

    return (task: DayTask, onComplete: () => void) => {
        const untick = task.done && task.can_untick ? () => router.delete(day.tasks.uncomplete(task.task_id).url, keep) : undefined;

        return {
            done: task.done,
            label: `${task.done ? (untick ? t('Mark as not done') : t('Done')) : t('Mark as done')}: ${task.name}`,
            onToggle: task.done ? untick : onComplete,
        };
    };
}

/** Figma: Tile / timer. The running timer beside the day from 768 px; a phone has the row and the strip, never a third. */
export function TimerTile({ running }: { running: Pick<Running, 'id' | 'started_at' | 'task' | 'client'> }) {
    const { t } = useTranslation();
    const what = [running.task ?? t('Untitled session'), running.client].filter(Boolean).join(' · ');

    return (
        <Tile kind="timer" className={styles.timerTile} aria-label={t('Timer running')}>
            <Stat kind="tile" tone="on-colour" label={t('Timer running')} value={<Elapsed startedAt={running.started_at} />} sub={what} />
            <StopButton timerId={running.id} onColour />
        </Tile>
    );
}

/**
 * Figma: Dziś (1075:578, phone 1075:780). The day's tile: the weekday, the picks with their tick and timer, the next
 * pick, what waits from earlier days, work off the plan, the close and tomorrow; beside it the timer and the month.
 */
export function DayView({ date, picks, maxPicks, month, closed, timers, running, offPlan, looseTimers, carried, tomorrowPicks }: TodayProps) {
    const { t, i18n } = useTranslation();
    const errors = usePage().props.errors as Record<string, string>;
    const { pending, start } = useTimerActions();
    const check = useTaskCheck();
    const free = maxPicks - picks.length;
    const timerOf = (row: DayTask) => (row.running_id ? timers.find((timer) => timer.id === row.running_id) : undefined);
    const pick = (taskId: number) => router.post(day.picks.store().url, { task_id: taskId, date }, keep);

    return (
        <main>
            <TileGrid>
                <section className={styles.day}>
                    <header className={styles.heading}>
                        <Poster>{weekdayName(parseDay(date), i18n.language)}</Poster>
                        <p className={styles.meta}>
                            {longDate(date, i18n.language)} · {t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks })}
                        </p>
                    </header>
                    <Tile kind="list" as="div" className={styles.dayList}>
                        <TileRows className={styles.picks}>
                            {picks.map((row) => {
                                const timer = timerOf(row);

                                return (
                                    <Row
                                        key={row.id}
                                        check={check(row, () => row.id && router.post(day.picks.complete(row.id).url, {}, keep))}
                                        title={row.name}
                                        href={timer ? focusView(timer.id) : tasks.show(row.task_id)}
                                        time={
                                            timer ? (
                                                <Elapsed startedAt={timer.started_at} />
                                            ) : row.done && row.minutes ? (
                                                durationText(row.minutes, t)
                                            ) : undefined
                                        }
                                        timeTone={timer ? 'over' : 'neutral'}
                                        wideMeta={row.client}
                                        action={
                                            timer ? (
                                                <StopButton timerId={timer.id} />
                                            ) : row.done ? undefined : (
                                                <SoloButton variant="secondary" size="control" disabled={pending} onClick={() => start(row.task_id)}>
                                                    {t('Start')}
                                                </SoloButton>
                                            )
                                        }
                                        more={
                                            timer || row.done ? undefined : (
                                                <RowMenu name={row.name}>
                                                    <ActionMenuItem onSelect={() => row.id && router.delete(day.picks.destroy(row.id).url, keep)}>
                                                        {t('Remove from today')}
                                                    </ActionMenuItem>
                                                </RowMenu>
                                            )
                                        }
                                    />
                                );
                            })}
                            {free > 0 && (
                                <Row
                                    title={picks.length === 0 ? t('Pick the first task') : t('Pick another')}
                                    meta={<span className={styles.phoneOnly}>{t('{{count}} free', { count: free })}</span>}
                                    wideMeta={t('{{count}} free slots', { count: free })}
                                    action={
                                        <SoloButtonLink href={today.tasks()} variant="secondary" size="control">
                                            {t('Add')}
                                        </SoloButtonLink>
                                    }
                                />
                            )}
                        </TileRows>
                        {errors.task_id && (
                            <TileInset>
                                <p className={styles.error}>{errors.task_id}</p>
                            </TileInset>
                        )}
                        {carried.length > 0 && (
                            <>
                                <GroupHeading label={t('From earlier days')} note={carried.length} />
                                <TileRows>
                                    {carried.map((row) => (
                                        <Row
                                            key={row.id}
                                            title={row.name}
                                            href={tasks.show(row.task_id)}
                                            wideMeta={[row.client, fromDay(row.from, date, i18n.language, t)].filter(Boolean).join(' · ')}
                                            action={
                                                <SoloButton variant="secondary" size="control" disabled={free <= 0} onClick={() => pick(row.task_id)}>
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
                            </>
                        )}
                        {offPlan.length > 0 && (
                            <>
                                <GroupHeading label={t('Off plan')} note={offPlan.length} />
                                <TileRows>
                                    {offPlan.map((row) => {
                                        const timer = timerOf(row);

                                        return (
                                            <Row
                                                key={row.task_id}
                                                check={check(row, () => router.post(day.tasks.complete(row.task_id).url, {}, keep))}
                                                title={row.name}
                                                href={timer ? focusView(timer.id) : tasks.show(row.task_id)}
                                                time={
                                                    timer ? (
                                                        <Elapsed startedAt={timer.started_at} />
                                                    ) : row.minutes ? (
                                                        durationText(row.minutes, t)
                                                    ) : undefined
                                                }
                                                timeTone={timer ? 'over' : 'neutral'}
                                                wideMeta={row.client}
                                                action={
                                                    timer ? (
                                                        <StopButton timerId={timer.id} />
                                                    ) : !row.done && free > 0 ? (
                                                        <SoloButton variant="secondary" size="control" onClick={() => pick(row.task_id)}>
                                                            {t('To the plan')}
                                                        </SoloButton>
                                                    ) : undefined
                                                }
                                                more={
                                                    timer && !row.done && free > 0 ? (
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
                                            time={<Elapsed startedAt={timer.started_at} />}
                                            timeTone="over"
                                            wideMeta={[timer.client, timer.project].filter(Boolean).join(' · ')}
                                            action={<StopButton timerId={timer.id} />}
                                        />
                                    ))}
                                </TileRows>
                            </>
                        )}
                        <TileActions>
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
                                {tomorrowPicks > 0 ? t('Edit the plan') : t('Plan tomorrow')}
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
                    {running && <TimerTile running={running} />}
                    <MonthCard look="tile" month={month} />
                </TileStack>
            </TileGrid>
        </main>
    );
}
