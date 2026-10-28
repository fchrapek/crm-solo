import tasks from '@/routes/tasks';
import { Link, type InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './task-row.module.css';

export interface DayTask {
    /** The pick id; absent on the full task list. */
    id?: number;
    task_id: number;
    name: string;
    client: string | null;
    project: string | null;
    done: boolean;
    /** Done by a tick the CRM can take back; false for a card completed on its board. */
    can_untick?: boolean;
    minutes?: number;
    /** On the full list: already one of the day's picks. */
    picked?: boolean;
    /** Off plan: the running timer on this task, if any. */
    running_id?: number | null;
}

interface TaskRowProps {
    task: DayTask;
    /** Shows a checkbox; clicking it completes the task. The full list omits it. */
    onComplete?: () => void;
    /** Lets a ticked checkbox be clicked again to take a mistaken tick back. */
    onUncomplete?: () => void;
    trailing?: ReactNode;
    compact?: boolean;
    /** Where the title leads, e.g. the focus view of the running task; the task page by default. */
    href?: InertiaLinkProps['href'];
    due?: ReactNode;
    /** done is the Done row in Podsumowanie's box, queue the timer's Queue row: title and one status, no client or project. */
    kind?: 'day' | 'client' | 'done' | 'queue';
    /** Queue row State=Current: the task the timer runs on. */
    current?: boolean;
    /** A ticked row keeps its title as is: a checklist step is done, not struck out like a finished task. */
    plainDone?: boolean;
    className?: string;
}

const KIND_CLASS = { day: styles.day, client: styles.clientRow, done: styles.doneRow, queue: styles.queueRow };

/**
 * Figma: A2f / Task row; kind client is Client task row, done the Done row, queue the Queue row.
 * compact is the A2f / List row of Wszystkie zadania and Propozycje: the title, the project as end meta, one action.
 */
export function TaskRow({ task, onComplete, onUncomplete, trailing, compact, href, due, kind = 'day', current = false, plainDone = false, className }: TaskRowProps) {
    const { t } = useTranslation();
    const brief = kind === 'done' || kind === 'queue';
    const where = brief ? '' : [task.client, task.project].filter(Boolean).join(' · ');
    const untick = task.done && task.can_untick && onUncomplete ? onUncomplete : undefined;
    const link = href ?? (task.task_id > 0 ? tasks.show(task.task_id) : undefined);

    return (
        <li
            className={[
                styles.row,
                compact ? styles.compact : KIND_CLASS[kind],
                current ? styles.current : '',
                task.done ? styles.done : '',
                plainDone ? styles.plainDone : '',
                className,
            ]
                .filter(Boolean)
                .join(' ')}
            aria-current={current ? 'step' : undefined}
        >
            {onComplete && (
                <button
                    type="button"
                    className={[styles.check, untick ? styles.untick : ''].filter(Boolean).join(' ')}
                    onClick={task.done ? untick : onComplete}
                    disabled={task.done && !untick}
                    aria-label={task.done ? (untick ? t('Mark as not done') : t('Done')) : t('Mark as done')}
                    title={untick ? t('Mark as not done') : undefined}
                    aria-pressed={task.done}
                >
                    {task.done && <span aria-hidden="true">✓</span>}
                </button>
            )}
            <div className={styles.text}>
                <p className={styles.title} title={task.name}>
                    {link ? (
                        <Link href={link} className={styles.titleLink}>
                            <span className={styles.titleText}>{task.name}</span>
                        </Link>
                    ) : (
                        <span className={styles.titleText}>{task.name}</span>
                    )}
                </p>
                {where && (
                    <p className={styles.meta} title={where}>
                        {task.client && <span className={styles.client}>{task.client}</span>}
                        {task.client && task.project && <span className={styles.sep}>·</span>}
                        {task.project && <span className={styles.project}>{task.project}</span>}
                    </p>
                )}
            </div>
            {due !== undefined && <div className={styles.due}>{due}</div>}
            {trailing && <div className={styles.trailing}>{trailing}</div>}
        </li>
    );
}
