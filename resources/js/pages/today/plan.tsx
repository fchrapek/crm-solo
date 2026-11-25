import { type MonthData } from '@/components/solo/month-card';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { listDetails, Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileActions, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { parseDay, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { type SharedData, type ShellTimer } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { AddOrPick } from './add-or-pick';
import { placesNote, taskDetails, TimerTile, timerOf, useTaskCheck } from './day';
import { DayHeading, DayMonth } from './day-header';
import styles from './day.module.css';
import { dayLabel, dayPills, dayPlace, type DayNav } from './place';

export interface PlanSuggestions {
    tasks: DayTask[];
    /** Today's unfinished picks among the suggestions; they come first. */
    leftover: number;
    /** Every open task, the size of All tasks. */
    open: number;
}

interface PlanProps {
    date: string;
    /** Whether the day takes new picks: from today to the end of next week. A moved pick may sit further ahead. */
    plannable: boolean;
    closed: boolean;
    maxPicks: number;
    warnFull: boolean;
    /** Set when the plan is another day's than tomorrow's. */
    day?: DayNav;
    picks: DayTask[];
    suggestions: PlanSuggestions;
    month: MonthData;
    [key: string]: unknown;
}

const keep = { preserveScroll: true };

/** The full list for the plan's own day; from a day's page it carries the date and Done returns to that page. */
export const planListHref = (date: string, nav?: DayNav): string => today.tasks({ query: nav ? { for: date, from: 'day' } : { for: 'tomorrow' } }).url;

/**
 * Figma: Tomorrow 2E (1375:26662, phone 1375:26876), Shared · Row details (1555:77908, phone 1556:19881). Suggestions as
 * a group inside the plan's list, in the plan's order: the title, a running timer's time, the chevron to the client,
 * project, due day and logged time, a quiet For tomorrow; the last row opens the full list.
 */
export function Suggestions({
    date,
    suggestions,
    list,
    tomorrow = true,
    timers = [],
}: {
    date: string;
    suggestions: PlanSuggestions;
    list: string;
    tomorrow?: boolean;
    /** The account's running timers, for the row whose task one runs on. */
    timers?: ShellTimer[];
}) {
    const { t, i18n } = useTranslation();
    const details = listDetails(suggestions.tasks.map((task) => taskDetails(task, t, i18n.language)));

    return (
        <>
            <GroupHeading label={t('Suggestions')} note={t('from today, then deadlines')} />
            <TileRows>
                {suggestions.tasks.map((task, index) => (
                    <Row
                        key={task.task_id}
                        title={task.name}
                        href={tasks.show(task.task_id)}
                        running={timerOf(task, timers)?.started_at}
                        details={details[index]}
                        action={
                            <SoloButton
                                variant="quiet"
                                size="control"
                                className={styles.rowAction}
                                onClick={() => router.post(day.picks.store().url, { task_id: task.task_id, date }, keep)}
                            >
                                {tomorrow ? t('For tomorrow') : t('For this day')}
                            </SoloButton>
                        }
                    />
                ))}
                <Row
                    wrap
                    title={t('All tasks ({{count}})', { count: suggestions.open })}
                    action={
                        <SoloButtonLink href={list} variant="quiet" size="control" className={styles.rowAction}>
                            {t('Open')}
                        </SoloButtonLink>
                    }
                />
            </TileRows>
        </>
    );
}

/**
 * Figma: Tomorrow 2E (1375:26662, phone 1375:26876; the add field 1412:22227). Tomorrow's picks and the add field, then
 * while places are free and there is something to suggest the Suggestions group in the same list, and the action leads
 * back to the day, otherwise it is Save the plan. Beside it from 1100 px, below it otherwise, the timer and the month.
 */
export default function PlanTomorrow() {
    const { date, plannable, maxPicks, warnFull, picks, suggestions, month, timer, timers, day: nav } = usePage<SharedData & PlanProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const check = useTaskCheck();
    const proposing = plannable && suggestions.tasks.length > 0;
    const list = planListHref(date, nav);

    return (
        <SoloSurface day="paper">
            <Head title={nav ? dayLabel(date, i18n.language) : t('Plan tomorrow')} />
            <main className={styles.summary}>
                <DayHeading title={weekdayName(parseDay(date), i18n.language)} />
                <TileGrid>
                    <Tile kind="list">
                        <TileHeader label={nav ? t('Plan of the day') : t('Tomorrow')} />
                        <TileRows>
                            {picks.map((pick) => (
                                <Row
                                    key={pick.id}
                                    check={check(pick, () => pick.id && router.post(day.picks.complete(pick.id).url, {}, keep))}
                                    title={pick.name}
                                    href={tasks.show(pick.task_id)}
                                    wideMeta={pick.client}
                                    action={
                                        <SoloButton
                                            variant="quiet"
                                            size="control"
                                            className={styles.rowAction}
                                            aria-label={`${t('Remove')}: ${pick.name}`}
                                            onClick={() => pick.id && router.delete(day.picks.destroy(pick.id).url, keep)}
                                        >
                                            {t('Remove')}
                                        </SoloButton>
                                    }
                                />
                            ))}
                            {plannable && (
                                <AddOrPick
                                    date={date}
                                    picked={picks.length}
                                    maxPicks={maxPicks}
                                    warnFull={warnFull}
                                    listHref={list}
                                    note={placesNote(picks.length, maxPicks, warnFull, t)}
                                />
                            )}
                        </TileRows>
                        {(proposing || !plannable || errors.task_id) && (
                            <TileInset>
                                {!plannable && <TileNote>{t('Picks can be set from today to the end of next week.')}</TileNote>}
                                {proposing && (
                                    <TileNote>
                                        {suggestions.leftover > 0
                                            ? t('{{count}} left over from today, first in the suggestions.', { count: suggestions.leftover })
                                            : t('Nothing left over from today.')}
                                    </TileNote>
                                )}
                                {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                            </TileInset>
                        )}
                        {proposing && <Suggestions date={date} suggestions={suggestions} list={list} tomorrow={!nav} timers={timers} />}
                        <TileActions apart>
                            <SoloButtonLink href={dashboard()} size="control">
                                {proposing ? t('Back to the day') : t('Save the plan')}
                            </SoloButtonLink>
                        </TileActions>
                    </Tile>
                    <TileStack as="aside">
                        {timer && <TimerTile running={timer} timers={timers} />}
                        <DayMonth month={month} date={date} />
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

PlanTomorrow.place = ((props, context) =>
    props.day ? dayPlace(props.day, context) : { pills: dayPills('tomorrow', context.t, props.closed) }) satisfies PlaceResolver<PlanProps>;
