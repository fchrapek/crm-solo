import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { dayMonth, isoWeek, parseDay, weekdayName } from '@/components/solo/day-format';
import { DayRow } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { Poster, SectionHead, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import styles from './today.module.css';

interface PlanProps {
    date: string;
    closed: boolean;
    maxPicks: number;
    picks: DayTask[];
    [key: string]: unknown;
}

/** Plan tomorrow: reached from Gotowe. Paper, because planning is choosing. */
export default function PlanTomorrow() {
    const { date, maxPicks, picks, closed } = usePage<SharedData & PlanProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const d = parseDay(date);
    const free = maxPicks - picks.length;

    return (
        <div className={styles.page} data-day="paper">
            <Head title={t('Plan tomorrow')} />
            <div className={styles.frame}>
                <Masthead />
                <main className={styles.grid}>
                    <div className={styles.head}>
                        <DayRow current="tomorrow" closed={closed}>
                            {dayMonth(d, i18n.language)} · {t('week {{n}}', { n: isoWeek(d) })}
                        </DayRow>
                        <Poster>{weekdayName(d, i18n.language)}</Poster>
                    </div>
                    <div className={styles.main}>
                        <div>
                            <SectionHead label={t('Tomorrow')} meta={t('{{count}} of {{max}} picked', { count: picks.length, max: maxPicks })} />
                            <ul className={styles.list}>
                                {picks.map((pick) => (
                                    <TaskRow
                                        key={pick.id}
                                        task={pick}
                                        trailing={
                                            <SoloButton
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => pick.id && router.delete(day.picks.destroy(pick.id).url, { preserveScroll: true })}
                                            >
                                                {t('Remove')}
                                            </SoloButton>
                                        }
                                    />
                                ))}
                            </ul>
                        </div>
                        {free > 0 && (
                            <Link href={today.tasks({ query: { na: 'jutro' } })} className={styles.slot}>
                                + {t('{{count}} free slots · pick from the task list', { count: free })}
                            </Link>
                        )}
                        {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                        <div className={styles.actions}>
                            <SoloButtonLink href={dashboard()} size="md" variant="primary">
                                {t('Save the plan')} →
                            </SoloButtonLink>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    );
}
