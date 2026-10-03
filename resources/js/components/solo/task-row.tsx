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
    /** Makes the title a link, e.g. into the focus view of the running task. */
    href?: InertiaLinkProps['href'];
}

/** Figma: Task row (Open / Done). */
export function TaskRow({ task, onComplete, onUncomplete, trailing, compact, href }: TaskRowProps) {
    const { t } = useTranslation();
    const where = [task.client, task.project].filter(Boolean).join(' · ');
    const untick = task.done && task.can_untick && onUncomplete ? onUncomplete : undefined;

    return (
        <li className={[styles.row, compact ? styles.compact : '', task.done ? styles.done : ''].filter(Boolean).join(' ')}>
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
                <p className={styles.title}>
                    {href ? (
                        <Link href={href} className={styles.titleLink}>
                            {task.name}
                        </Link>
                    ) : (
                        task.name
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
            {trailing && <div className={styles.trailing}>{trailing}</div>}
        </li>
    );
}
