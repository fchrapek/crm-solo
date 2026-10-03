import { Head, Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { dayMonth, parseDay } from '@/components/solo/day-format';
import { DayRow } from '@/components/solo/day-trail';
import { Masthead } from '@/components/solo/masthead';
import { SectionHead, SoloButton } from '@/components/solo/primitives';
import { TaskRow, type DayTask } from '@/components/solo/task-row';
import { dashboard } from '@/routes';
import day from '@/routes/day';
import today from '@/routes/today';
import { type SharedData } from '@/types';
import styles from './today.module.css';

interface TasksProps {
    date: string;
    closed: boolean;
    target: 'today' | 'tomorrow';
    maxPicks: number;
    pickedCount: number;
    groups: { client: string; tasks: DayTask[] }[];
    [key: string]: unknown;
}

/** The full list of open tasks, grouped by client. The only place picks are chosen, so the day screens stay quiet. */
export default function TaskList() {
    const { date, target, maxPicks, pickedCount, groups, closed } = usePage<SharedData & TasksProps>().props;
    const errors = usePage().props.errors as Record<string, string>;
    const { t, i18n } = useTranslation();
    const full = pickedCount >= maxPicks;
    const total = groups.reduce((n, g) => n + g.tasks.length, 0);
    const back = target === 'tomorrow' ? today.plan() : dashboard();

    return (
        <div className={styles.page} data-day="paper">
            <Head title={t('All tasks')} />
            <div className={styles.frame}>
                <Masthead />
                <main className={styles.grid}>
                    <div className={styles.head}>
                        <DayRow closed={closed}>
                            {target === 'tomorrow' ? t('Picking for tomorrow') : t('Picking for today')} · {dayMonth(parseDay(date), i18n.language)}
                        </DayRow>
                        <h1 className={styles.title}>{t('All tasks')}</h1>
                        <div className={styles.actions}>
                            <p className={styles.quiet}>{t('{{count}} of {{max}} picked', { count: pickedCount, max: maxPicks })}</p>
                            <Link href={back} className={styles.addRow}>
                                ← {target === 'tomorrow' ? t('Back to the plan') : t('Back to today')}
                            </Link>
                        </div>
                        {errors.task_id && <p className={styles.error}>{errors.task_id}</p>}
                    </div>
                    <div className={[styles.main, styles.mainWide].join(' ')}>
                        {groups.map((group) => (
                            <section key={group.client}>
                                <SectionHead label={group.client} meta={group.tasks.length} />
                                <ul className={styles.list}>
                                    {group.tasks.map((task) => (
                                        <TaskRow
                                            key={task.task_id}
                                            task={{ ...task, client: null }}
                                            compact
                                            trailing={
                                                task.picked ? (
                                                    <span>{t('Picked')}</span>
                                                ) : (
                                                    <SoloButton
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={full}
                                                        onClick={() => router.post(day.picks.store().url, { task_id: task.task_id, date }, { preserveScroll: true })}
                                                    >
                                                        {target === 'tomorrow' ? t('+ Tomorrow') : t('+ Today')}
                                                    </SoloButton>
                                                )
                                            }
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                        {total === 0 && <p className={styles.quiet}>{t('No open tasks left to pick.')}</p>}
                    </div>
                </main>
            </div>
        </div>
    );
}
