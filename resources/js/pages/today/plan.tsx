import { BottomAction } from '@/components/solo/bottom-action';
import { DayTrail } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { Poster, SectionAction, SectionHead, SoloButtonLink, touchTargetClass } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { parseDay, weekdayName } from '@/lib/dates';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './today.module.css';

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

/** Figma: Propozycje. A few open tasks to put on tomorrow in one click, deadlines first, and the way to the full list. */
export function Suggestions({ date, suggestions }: { date: string; suggestions: PlanSuggestions }) {
    const { t } = useTranslation();

    return (
        <>
            <section className={styles.card}>
                <SectionHead label={t('Suggestions')} meta={t('deadlines first')} />
                <ul className={styles.list}>
                    {suggestions.tasks.map((task) => (
                        <TaskRow
                            key={task.task_id}
                            compact
                            task={{ ...task, client: null, project: null }}
                            trailing={
                                <SectionAction
                                    className={touchTargetClass}
                                    onClick={() => router.post(day.picks.store().url, { task_id: task.task_id, date }, { preserveScroll: true })}
                                >
                                    {t('For tomorrow')}
                                </SectionAction>
                            }
                        />
                    ))}
                </ul>
            </section>
            <Link href={today.tasks({ query: { na: 'jutro' } })} className={`${styles.allTasks} ${touchTargetClass}`}>
                {t('All tasks ({{count}})', { count: suggestions.open })} →
            </Link>
        </>
    );
}

/** Figma: A2f / Free slots. Beside Propozycje it points at them; with nothing to suggest it leads to the full list. */
export function FreeSlots({ free, proposing }: { free: number; proposing: boolean }) {
    const { t } = useTranslation();

    return proposing ? (
        <p className={styles.slot}>
            <span className={styles.phoneOnly}>{t('{{count}} free slots · add from the suggestions below', { count: free })}</span>
            <span className={styles.wideOnly}>{t('{{count}} free slots · add from the suggestions beside', { count: free })}</span>
        </p>
    ) : (
        <Link href={today.tasks({ query: { na: 'jutro' } })} className={styles.slot}>
            {t('{{count}} free slots · pick from the task list', { count: free })}
        </Link>
    );
}

/**
 * Plan tomorrow: reached from Podsumowanie. Paper, because planning is choosing. While slots are free and there is
 * something to suggest, Propozycje sit beside the list (below it on phones); once the day is full, or nothing is left
 * to suggest, the free slots lead to the full list and the action is Zapisz plan.
 */
export default function PlanTomorrow() {
    const { date, maxPicks, picks, closed, suggestions } = usePage<SharedData & PlanProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const free = maxPicks - picks.length;
    const proposing = free > 0 && suggestions.tasks.length > 0;

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('Plan tomorrow')} />
            <div className={styles.frame}>
                <Masthead />
                <main className={styles.grid}>
                    {/* the head spans the page, so Propozycje line up with the list */}
                    <div className={`${styles.head} ${styles.headApart}`}>
                        <DayTrail current="tomorrow" closed={closed} />
                        <Poster>{weekdayName(parseDay(date), i18n.language)}</Poster>
                    </div>
                    <div className={splitClass}>
                        <div className={`${splitMainClass} ${styles.main}`}>
                            <div className={styles.card}>
                                <SectionHead label={t('Tomorrow')} meta={t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks })} />
                                <ul className={styles.list}>
                                    {picks.map((pick) => (
                                        <TaskRow
                                            key={pick.id}
                                            task={{ ...pick, project: null }}
                                            onComplete={() => pick.id && router.post(day.picks.complete(pick.id).url, {}, { preserveScroll: true })}
                                            onUncomplete={() => router.delete(day.tasks.uncomplete(pick.task_id).url, { preserveScroll: true })}
                                            trailing={
                                                <SectionAction
                                                    className={touchTargetClass}
                                                    onClick={() => pick.id && router.delete(day.picks.destroy(pick.id).url, { preserveScroll: true })}
                                                >
                                                    {t('Remove')}
                                                </SectionAction>
                                            }
                                        />
                                    ))}
                                </ul>
                            </div>
                            {free > 0 && <FreeSlots free={free} proposing={proposing} />}
                            {proposing && (
                                <p className={styles.note}>
                                    {suggestions.leftover > 0
                                        ? t('{{count}} left over from today, first in the suggestions.', { count: suggestions.leftover })
                                        : t('Nothing left over from today.')}
                                </p>
                            )}
                            {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                            <div className={`${styles.actions} ${styles.barSlot} ${styles.planAction}`}>
                                <BottomAction>
                                    <SoloButtonLink href={dashboard()} size="md" variant="ink">
                                        {proposing ? t('Back to the day') : t('Save the plan')} →
                                    </SoloButtonLink>
                                </BottomAction>
                            </div>
                        </div>
                        {proposing && (
                            <aside className={`${splitSideClass} ${styles.rail} ${styles.suggestions}`}>
                                <Suggestions date={date} suggestions={suggestions} />
                            </aside>
                        )}
                    </div>
                </main>
            </div>
        </SoloSurface>
    );
}
