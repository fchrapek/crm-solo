import { ActionMenuItem } from '@/components/solo/action-menu';
import { type MonthData } from '@/components/solo/month-card';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type DayTask } from '@/components/solo/task-row';
import { GroupHeading, Tile, TileGrid, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { capitalize, durationText, parseDay, shortDate, weekdayName } from '@/lib/dates';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AddOrPick } from './add-or-pick';
import { TimerTile } from './day';
import { DayHeading, DayMonth } from './day-header';
import styles from './day.module.css';
import { freeNote, PickedCount } from './picked';
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
    warnFull: boolean;
    month: MonthData;
    days: WeekDay[];
    [key: string]: unknown;
}

const keep = { preserveScroll: true };

/**
 * Figma: Week, one date (1091:28683). Its picks under the date and its count; a day below five picks offers
 * Choose. A past day's unfinished pick stays on its day, takeable into today or droppable.
 */
export function DaySection({ entry, today: todayDate, maxPicks, warnFull }: { entry: WeekDay; today: string; maxPicks: number; warnFull: boolean }) {
    const { t, i18n } = useTranslation();
    const wide = useSoloWide();
    const past = entry.date < todayDate;
    const isToday = entry.date === todayDate;
    const done = entry.picks.filter((pick) => pick.done).length;
    const free = maxPicks - entry.picks.length;
    const count =
        past && entry.picks.length === 0 ? undefined : past ? (
            t('{{done}} of {{count}} done', { done, count: entry.picks.length })
        ) : (
            <PickedCount count={entry.picks.length} max={maxPicks} warn={warnFull} />
        );
    const pickHref = isToday ? today.tasks().url : today.tasks({ query: { for: entry.date } }).url;
    const canPick = entry.plannable && !entry.closed;
    const empty = entry.picks.length === 0;
    const [adding, setAdding] = useState(false);
    const writeIn = useRef<HTMLButtonElement>(null);
    const actions = (
        <>
            <SoloButton ref={writeIn} variant="quiet" size="control" onClick={() => setAdding(true)}>
                {t('Write in a task')}
            </SoloButton>
            <SoloButtonLink href={pickHref} variant="secondary" size="control">
                {t('Choose')}
            </SoloButtonLink>
        </>
    );
    const addField = canPick && (
        <AddOrPick
            date={entry.date}
            picked={entry.picks.length}
            maxPicks={maxPicks}
            warnFull={warnFull}
            listHref={pickHref}
            opened={adding}
            onClose={() => setAdding(false)}
            opener={writeIn}
        />
    );

    return (
        <>
            <GroupHeading
                label={capitalize(weekdayName(parseDay(entry.date), i18n.language))}
                note={shortDate(entry.date, i18n.language)}
                current={isToday ? t('today') : undefined}
                quiet={past}
                end={count}
            />
            {empty && canPick ? (
                <TileRows>
                    {adding && wide ? (
                        addField
                    ) : (
                        <Row title={<span className={styles.quietTitle}>{t('Nothing planned')}</span>} stack action={actions} />
                    )}
                    {!wide && addField}
                </TileRows>
            ) : empty ? (
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
                    {canPick &&
                        (adding && wide ? (
                            addField
                        ) : (
                            <Row
                                title={t('Pick another')}
                                meta={free > 0 ? <span className={styles.phoneOnly}>{freeNote(t, free, warnFull, true)}</span> : undefined}
                                wideMeta={freeNote(t, free, warnFull)}
                                stack
                                action={actions}
                            />
                        ))}
                    {!wide && addField}
                </TileRows>
            )}
        </>
    );
}

/**
 * Figma: Week (1091:28683, phone 1091:29016; the head 1516:27842, W1). Seven dates, Monday first, in one tile; this
 * week or the next as a second row of pills; beside them the running timer and the month tile naming the week's dates.
 */
export default function Week() {
    const { today: todayDate, week, maxPicks, warnFull, month, days, timer } = usePage<SharedData & WeekProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t } = useTranslation();

    return (
        <SoloSurface day="paper">
            <Head title={t('Week')} />
            <main className={styles.summary}>
                <DayHeading title={t('Week')}>
                    <div className={styles.headPills}>
                        <Pills
                            label={t('Week')}
                            items={[
                                { label: t('This week'), href: today.week(), active: week === 'this' },
                                { label: t('Next week'), href: today.week({ query: { next: 1 } }), active: week === 'next' },
                            ]}
                        />
                    </div>
                </DayHeading>
                <TileGrid>
                    <Tile kind="list">
                        {errors.task_id && (
                            <TileInset>
                                <p className={styles.error}>{errors.task_id}</p>
                            </TileInset>
                        )}
                        {days.map((entry) => (
                            <DaySection key={entry.date} entry={entry} today={todayDate} maxPicks={maxPicks} warnFull={warnFull} />
                        ))}
                    </Tile>
                    <TileStack as="aside">
                        {timer && <TimerTile running={timer} />}
                        <DayMonth month={month} date={days[0]?.date ?? todayDate} until={days[days.length - 1]?.date} />
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

Week.place = ((props, { t }) => ({ pills: dayPills('week', t, props.closed) })) satisfies PlaceResolver<WeekProps>;
