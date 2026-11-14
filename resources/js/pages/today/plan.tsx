import { Poster, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { type DayTask } from '@/components/solo/task-row';
import { Tile, TileActions, TileGrid, TileHeader, TileInset, TileNote, TileRows } from '@/components/solo/tile';
import { longDate, parseDay, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import tasks from '@/routes/tasks';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useTaskCheck } from './day';
import styles from './day.module.css';
import { dayPills } from './place';

export interface PlanSuggestions {
    tasks: DayTask[];
    /** Today's unfinished picks among the suggestions; they come first. */
    leftover: number;
    /** Every open task, the size of Wszystkie zadania. */
    open: number;
}

interface PlanProps {
    date: string;
    closed: boolean;
    maxPicks: number;
    picks: DayTask[];
    suggestions: PlanSuggestions;
    [key: string]: unknown;
}

const keep = { preserveScroll: true };
const fullList = () => today.tasks({ query: { na: 'jutro' } });

/** Figma: Jutro, Propozycje (1091:27911). A few open tasks to put on tomorrow in one click, deadlines first, and the way to the full list. */
export function Suggestions({ date, suggestions }: { date: string; suggestions: PlanSuggestions }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list" as="aside">
            <TileHeader label={t('Suggestions')} end={t('deadlines first')} />
            <TileRows>
                {suggestions.tasks.map((task) => (
                    <Row
                        key={task.task_id}
                        wrap
                        title={task.name}
                        href={tasks.show(task.task_id)}
                        action={
                            <SoloButton
                                variant="secondary"
                                size="control"
                                onClick={() => router.post(day.picks.store().url, { task_id: task.task_id, date }, keep)}
                            >
                                {t('For tomorrow')}
                            </SoloButton>
                        }
                    />
                ))}
                <Row
                    wrap
                    title={t('All tasks ({{count}})', { count: suggestions.open })}
                    action={
                        <SoloButtonLink href={fullList()} variant="quiet" size="control" className={styles.rowAction}>
                            {t('Open')}
                        </SoloButtonLink>
                    }
                />
            </TileRows>
        </Tile>
    );
}

/** The free places of tomorrow as the list's last row: beside Propozycje it points at them; with nothing to suggest it leads to the full list. */
export function FreeSlots({ free, proposing }: { free: number; proposing: boolean }) {
    const { t } = useTranslation();

    return proposing ? (
        <Row
            title={t('{{count}} free slots', { count: free })}
            meta={<span className={styles.phoneOnly}>{t('add from the suggestions below')}</span>}
            wideMeta={t('add from the suggestions beside')}
        />
    ) : (
        <Row
            title={t('{{count}} free slots', { count: free })}
            action={
                <SoloButtonLink href={fullList()} variant="secondary" size="control">
                    {t('Choose')}
                </SoloButtonLink>
            }
        />
    );
}

/**
 * Figma: Jutro (1091:27911, phone 1091:28116; the plan full 1091:28309). Tomorrow's picks; while places are free and
 * there is something to suggest, Propozycje stand beside the list (below it on a phone) and the action leads back to
 * the day; once the plan is full, or nothing is left to suggest, the free places lead to the full list and the
 * action is Zapisz plan.
 */
export default function PlanTomorrow() {
    const { date, maxPicks, picks, suggestions } = usePage<SharedData & PlanProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const check = useTaskCheck();
    const free = maxPicks - picks.length;
    const proposing = free > 0 && suggestions.tasks.length > 0;
    const picked = t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks });

    return (
        <SoloSurface day="paper">
            <Head title={t('Plan tomorrow')} />
            <main className={styles.summary}>
                <header className={styles.summaryHead}>
                    <Poster>{weekdayName(parseDay(date), i18n.language)}</Poster>
                    <p className={styles.meta}>
                        {longDate(date, i18n.language)} · {picked}
                    </p>
                </header>
                <TileGrid>
                    <Tile kind="list">
                        <TileHeader label={t('Tomorrow')} end={picked} />
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
                            {free > 0 && <FreeSlots free={free} proposing={proposing} />}
                        </TileRows>
                        {(proposing || errors.task_id) && (
                            <TileInset>
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
                        <TileActions>
                            <SoloButtonLink href={dashboard()} size="control">
                                {proposing ? t('Back to the day') : t('Save the plan')}
                            </SoloButtonLink>
                        </TileActions>
                    </Tile>
                    {proposing && <Suggestions date={date} suggestions={suggestions} />}
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

PlanTomorrow.place = ((props, { t }) => ({ pills: dayPills('tomorrow', t, props.closed) })) satisfies PlaceResolver<PlanProps>;
