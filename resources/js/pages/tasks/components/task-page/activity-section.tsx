import { EmptyBlock, SectionHead } from '@/components/solo/primitives';
import { minutesSince } from '@/lib/dates';
import tasks from '@/routes/tasks';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { activityLine, actorName, whenLabel } from './format';
import styles from './task-page.module.css';
import type { ActivityRow, LiveAgent, TaskActivityPage, TaskClock } from './types';

/** Figma: the agent line on top of Aktywność while an agent's timer runs on the task. */
function LiveRow({ live, now }: { live: LiveAgent; now: number }) {
    const { t } = useTranslation();

    return (
        <li className={styles.live} title={live.description ?? undefined}>
            <span className={styles.liveMark} aria-hidden="true" />
            <span className={styles.liveText}>
                {t('{{agent}} working · {{minutes}} min', { agent: live.agent, minutes: minutesSince(live.started_at, now) })}
            </span>
        </li>
    );
}

/** One line: when and who in the meta tone, then what happened, cut with an ellipsis unless the whole history is open. */
function Entry({ row, now, clock, locale }: { row: ActivityRow; now: number; clock: TaskClock; locale: string }) {
    const { t } = useTranslation();
    const sentence = activityLine(row, locale, t);

    return (
        <li className={styles.entry}>
            <p className={styles.entryLine} title={sentence}>
                <span className={styles.entryMeta}>
                    {whenLabel(row, now, clock.today, locale, t)} · {actorName(row.actor, t)} ·{' '}
                </span>
                {sentence}
            </p>
        </li>
    );
}

/**
 * Figma: Aktywność (770:56741; phones 849:20996): the live agent line, the latest entries (three, one on phones), then
 * Cała historia, which opens every row in place.
 */
export function ActivitySection({
    taskId,
    activity,
    full = false,
    live,
    clock,
    now,
    locale,
}: {
    taskId: number;
    activity: TaskActivityPage;
    /** The whole history is open: every row, each one wrapping. */
    full?: boolean;
    live: LiveAgent | null;
    clock: TaskClock;
    now: number;
    locale: string;
}) {
    const { t } = useTranslation();
    const rows = activity.rows;
    const empty = rows.length === 0 && live === null;
    // Collapsed entries are one line each, so any row may hide the end of its text.
    const cut = !full && rows.length > 0;

    return (
        <section className={styles.activity} aria-labelledby="task-activity">
            <SectionHead kind="body" label={<span id="task-activity">{t('Activity')}</span>} />
            {empty ? (
                <EmptyBlock>{t('No activity yet.')}</EmptyBlock>
            ) : (
                <ul className={styles.entries} data-full={full || undefined}>
                    {live !== null && <LiveRow live={live} now={now} />}
                    {rows.map((row) => (
                        <Entry key={row.key} row={row} now={now} clock={clock} locale={locale} />
                    ))}
                </ul>
            )}
            {(activity.more || cut) && (
                <Link
                    href={tasks.show(taskId, { query: { historia: 1 } }).url}
                    only={['activity']}
                    preserveScroll
                    preserveState
                    className={styles.historyLink}
                >
                    {t('Whole history')} →
                </Link>
            )}
        </section>
    );
}
