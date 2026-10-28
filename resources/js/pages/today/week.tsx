import { DayTrail } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { MonthCard, type MonthData } from '@/components/solo/month-card';
import { EmptyBlock, Poster, SectionHead, SoloButton, touchTargetClass } from '@/components/solo/primitives';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { Icon } from '@/components/ui/icon';
import { durationText, parseDay, shortDate, weekdayName } from '@/lib/dates';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import styles from './today.module.css';

interface WeekDay {
    date: string;
    plannable: boolean;
    closed: boolean;
    picks: (DayTask & { id: number })[];
}

interface WeekProps {
    today: string;
    week: 'this' | 'next';
    maxPicks: number;
    month: MonthData;
    days: WeekDay[];
    [key: string]: unknown;
}

/**
 * Figma: Tydzień (922:21329). One section per date, Monday first, each with its own count; a day below five picks
 * offers Wybierz. A past day's unfinished pick stays on its day, takeable into today or droppable.
 */
function DaySection({ entry, today: todayDate, maxPicks }: { entry: WeekDay; today: string; maxPicks: number }) {
    const { t, i18n } = useTranslation();
    const past = entry.date < todayDate;
    const isToday = entry.date === todayDate;
    const done = entry.picks.filter((pick) => pick.done).length;
    const name = `${weekdayName(parseDay(entry.date), i18n.language)} · ${shortDate(entry.date, i18n.language)}`;
    const meta =
        past && entry.picks.length === 0
            ? undefined
            : past
              ? t('{{done}} of {{count}} done', { done, count: entry.picks.length })
              : t('{{count}} of {{max}} picked', { count: entry.picks.length, max: maxPicks });
    const pickHref = isToday ? today.tasks().url : today.tasks({ query: { na: entry.date } }).url;

    return (
        <div className={styles.card}>
            <SectionHead label={isToday ? `${t('Today')} · ${name}` : name} meta={meta} quiet={!isToday} />
            {entry.picks.length === 0 && !entry.plannable ? (
                <EmptyBlock>{t('Nothing planned')}</EmptyBlock>
            ) : (
                <ul className={styles.list}>
                    {entry.picks.map((pick) => (
                        <TaskRow
                            key={pick.id}
                            task={pick}
                            trailing={
                                <>
                                    {pick.done && pick.minutes ? durationText(pick.minutes, t) : null}
                                    {past && !pick.done && <span className={styles.late}>{t('not done')}</span>}
                                    {past && !pick.done && (
                                        <SoloButton
                                            size="sm"
                                            variant="ghost"
                                            className={touchTargetClass}
                                            onClick={() => router.post(day.picks.store().url, { task_id: pick.task_id }, { preserveScroll: true })}
                                        >
                                            {t('For today')}
                                        </SoloButton>
                                    )}
                                    {/* a done pick on a day ahead still holds one of its five places, so it can go too */}
                                    {(past ? !pick.done : entry.plannable && !entry.closed) && (
                                        <SoloButton
                                            size="sm"
                                            variant="ghost"
                                            className={touchTargetClass}
                                            aria-label={`${t('Remove')}: ${pick.name}`}
                                            title={t('Remove')}
                                            onClick={() =>
                                                router.delete(past ? day.picks.dismiss(pick.id).url : day.picks.destroy(pick.id).url, { preserveScroll: true })
                                            }
                                        >
                                            <Icon name="close" size={16} />
                                        </SoloButton>
                                    )}
                                </>
                            }
                        />
                    ))}
                    {entry.plannable && !entry.closed && entry.picks.length < maxPicks && (
                        <li className={styles.addItem}>
                            <Link href={pickHref} className={`${styles.addRow} ${touchTargetClass}`}>
                                {entry.picks.length === 0 ? t('Choose') : t('Pick another')}
                            </Link>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

export default function Week() {
    const { today: todayDate, week, maxPicks, month, days } = usePage<SharedData & WeekProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t } = useTranslation();

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('Week')} />
            <div className={styles.frame}>
                <Masthead />
                <main className={`${splitClass} ${styles.grid}`}>
                    <div className={`${splitMainClass} ${styles.main}`}>
                        <div className={styles.head}>
                            <DayTrail current="week" />
                            <Poster>{t('Week')}</Poster>
                        </div>
                        <SegmentedControl
                            className={styles.weekSwitch}
                            label={t('Week')}
                            value={week}
                            options={[
                                { value: 'this', label: t('This week'), href: today.week() },
                                { value: 'next', label: t('Next week'), href: today.week({ query: { nastepny: 1 } }) },
                            ]}
                        />
                        {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                        {days.map((entry) => (
                            <DaySection key={entry.date} entry={entry} today={todayDate} maxPicks={maxPicks} />
                        ))}
                    </div>
                    <aside className={`${splitSideClass} ${styles.rail} ${styles.wideRail}`}>
                        <MonthCard month={month} date={todayDate} monthLink />
                    </aside>
                </main>
            </div>
        </SoloSurface>
    );
}
