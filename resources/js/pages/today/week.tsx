import { ActionMenuItem } from '@/components/solo/action-menu';
import { MonthCard, type MonthData } from '@/components/solo/month-card';
import { Poster, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileGrid, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { capitalize, dayMonth, durationText, longDate, parseDay, shortDate, weekdayName } from '@/lib/dates';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { TimerTile } from './day';
import styles from './day.module.css';
import { dayPills } from './place';

interface WeekDay {
    date: string;
    plannable: boolean;
    closed: boolean;
    picks: (DayTask & { id: number })[];
}

interface WeekProps {
    today: string;
    /** Today is closed. */
    closed: boolean;
    week: 'this' | 'next';
    maxPicks: number;
    month: MonthData;
    days: WeekDay[];
    [key: string]: unknown;
}

const keep = { preserveScroll: true };

/**
 * Figma: Tydzień, one date (1091:28683). Its picks under the date and its count; a day below five picks offers
 * Wybierz. A past day's unfinished pick stays on its day, takeable into today or droppable.
 */
export function DaySection({ entry, today: todayDate, maxPicks }: { entry: WeekDay; today: string; maxPicks: number }) {
    const { t, i18n } = useTranslation();
    const past = entry.date < todayDate;
    const isToday = entry.date === todayDate;
    const done = entry.picks.filter((pick) => pick.done).length;
    const free = maxPicks - entry.picks.length;
    const count =
        past && entry.picks.length === 0
            ? undefined
            : past
              ? t('{{done}} of {{count}} done', { done, count: entry.picks.length })
              : t('{{count}} of {{max}} picked', { count: entry.picks.length, max: maxPicks });
    const pickHref = isToday ? today.tasks().url : today.tasks({ query: { na: entry.date } }).url;
    const canPick = entry.plannable && !entry.closed && free > 0;
    const empty = entry.picks.length === 0;

    return (
        <>
            <GroupHeading
                label={capitalize(weekdayName(parseDay(entry.date), i18n.language))}
                note={shortDate(entry.date, i18n.language)}
                current={isToday ? t('today') : undefined}
                quiet={past}
                end={
                    empty && canPick ? (
                        <SoloButtonLink href={pickHref} variant="secondary" size="control">
                            {t('Choose')}
                        </SoloButtonLink>
                    ) : (
                        count
                    )
                }
            />
            {empty ? (
                <TileInset>
                    <TileNote>{t('Nothing planned')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {entry.picks.map((pick) => {
                        const late = past && !pick.done;
                        const removable = !past && entry.plannable && !entry.closed;

                        return (
                            <Row
                                key={pick.id}
                                check={{ done: pick.done, label: `${pick.done ? t('Done') : t('Not done')}: ${pick.name}` }}
                                title={pick.name}
                                href={tasks.show(pick.task_id)}
                                time={pick.done && pick.minutes ? durationText(pick.minutes, t) : undefined}
                                wideMeta={late ? t('not done') : pick.client}
                                action={
                                    late ? (
                                        <SoloButton
                                            variant="secondary"
                                            size="control"
                                            onClick={() => router.post(day.picks.store().url, { task_id: pick.task_id }, keep)}
                                        >
                                            {t('For today')}
                                        </SoloButton>
                                    ) : removable ? (
                                        // a done pick on a day ahead still holds one of its five places, so it can go too
                                        <SoloButton
                                            variant="quiet"
                                            size="control"
                                            className={styles.rowAction}
                                            aria-label={`${t('Remove')}: ${pick.name}`}
                                            onClick={() => router.delete(day.picks.destroy(pick.id).url, keep)}
                                        >
                                            {t('Remove')}
                                        </SoloButton>
                                    ) : undefined
                                }
                                more={
                                    late ? (
                                        <RowMenu name={pick.name}>
                                            <ActionMenuItem onSelect={() => router.delete(day.picks.dismiss(pick.id).url, keep)}>
                                                {t('Remove')}
                                            </ActionMenuItem>
                                        </RowMenu>
                                    ) : undefined
                                }
                            />
                        );
                    })}
                    {canPick && (
                        <Row
                            title={t('Pick another')}
                            meta={<span className={styles.phoneOnly}>{t('{{count}} free', { count: free })}</span>}
                            wideMeta={t('{{count}} free slots', { count: free })}
                            action={
                                <SoloButtonLink href={pickHref} variant="secondary" size="control">
                                    {t('Add')}
                                </SoloButtonLink>
                            }
                        />
                    )}
                </TileRows>
            )}
        </>
    );
}

/**
 * Figma: Tydzień (1091:28683, phone 1091:29016). Seven dates, Monday first, in one tile; this week or the next as a
 * second row of pills; beside them the running timer and the month.
 */
export default function Week() {
    const { today: todayDate, week, maxPicks, month, days, timer } = usePage<SharedData & WeekProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const range =
        days.length > 0 ? `${dayMonth(parseDay(days[0].date), i18n.language)} – ${longDate(days[days.length - 1].date, i18n.language)}` : null;

    return (
        <SoloSurface day="paper">
            <Head title={t('Week')} />
            <main className={styles.summary}>
                <header className={styles.summaryHead}>
                    <Poster>{t('Week')}</Poster>
                    {range !== null && <p className={styles.meta}>{range}</p>}
                    <div className={styles.headPills}>
                        <Pills
                            label={t('Week')}
                            items={[
                                { label: t('This week'), href: today.week(), active: week === 'this' },
                                { label: t('Next week'), href: today.week({ query: { nastepny: 1 } }), active: week === 'next' },
                            ]}
                        />
                    </div>
                </header>
                <TileGrid>
                    <Tile kind="list">
                        {errors.task_id && (
                            <TileInset>
                                <p className={styles.error}>{errors.task_id}</p>
                            </TileInset>
                        )}
                        {days.map((entry) => (
                            <DaySection key={entry.date} entry={entry} today={todayDate} maxPicks={maxPicks} />
                        ))}
                    </Tile>
                    <TileStack as="aside">
                        {timer && <TimerTile running={timer} />}
                        <MonthCard look="tile" month={month} />
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

Week.place = ((props, { t }) => ({ pills: dayPills('week', t, props.closed) })) satisfies PlaceResolver<WeekProps>;
