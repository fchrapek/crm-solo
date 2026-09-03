import { Clock, Copy, GitBranch, Pencil, Play, Terminal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';

import styles from './session-history.module.css';

export interface TaskSessionSummary {
    id: number;
    cli: string;
    base_branch: string;
    branch_name: string;
    worktree_path: string;
    started_at: string | null;
    ended_at: string | null;
    ended_reason: 'stopped' | 'crashed' | null;
    duration_minutes: number | null;
    is_running: boolean;
    time_entry: {
        id: number;
        title: string | null;
        task_id: number | null;
        duration_minutes: number | null;
        start_time: string | null;
        end_time: string | null;
        description: string | null;
        billable: boolean;
        pushed_to_clockify: boolean;
    } | null;
}

interface Props {
    sessions: TaskSessionSummary[];
    /**
     * Whether the host tmux session is alive. Drives the Resume affordance on
     * the latest closed row - Resume only makes sense when reattach will
     * actually succeed. Falsy hides the button.
     */
    tmuxAlive?: boolean;
    /**
     * Called when the user clicks Resume. The parent owns the fetch + reload
     * so this component stays UI-only and reusable. Receives the session row
     * the user clicked from for context (always the latest closed row today).
     */
    onResume?: (session: TaskSessionSummary) => void;
    /** True while the resume call is in flight - used to disable the button. */
    resuming?: boolean;
    /**
     * Called when the user clicks Edit on a session row. Parent owns the
     * dialog state so the dialog can live elsewhere in the tree (typical: at
     * the page level next to other dialogs).
     */
    onEditTime?: (session: TaskSessionSummary) => void;
}

const formatMinutes = (minutes: number | null): string => {
    if (minutes === null || minutes <= 0) return '0m';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
};

const formatStartedAt = (iso: string | null, locale: string): string => {
    if (iso === null) return ' - ';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export function SessionHistory({ sessions, tmuxAlive = false, onResume, resuming = false, onEditTime }: Props) {
    const { t, i18n } = useTranslation();
    const [copiedId, setCopiedId] = useState<number | null>(null);

    if (sessions.length === 0) {
        return null;
    }

    // The latest CLOSED row is the only one that can be resumed - tmux is a
    // single named session per task, so older closed rows point at the same
    // pane (or one that's long gone). Find by scanning the head of the array,
    // which is ordered newest-first by the server.
    const latestClosedId = sessions.find((s) => !s.is_running)?.id ?? null;

    const copyMerge = (session: TaskSessionSummary) => {
        navigator.clipboard.writeText(`git merge ${session.branch_name}`);
        setCopiedId(session.id);
        toast.success(t('Merge command copied'));
        window.setTimeout(() => setCopiedId((current) => (current === session.id ? null : current)), 1500);
    };

    return (
        <section className={styles.wrapper}>
            <h2 className={styles.title}>
                {t('Session history')} <span className={styles.titleCount}>({sessions.length})</span>
            </h2>
            <ul className={styles.list}>
                {sessions.map((s) => {
                    const dotClass = s.is_running
                        ? styles.statusDot
                        : s.ended_reason === 'crashed'
                          ? `${styles.statusDot} ${styles.statusDotCrashed}`
                          : `${styles.statusDot} ${styles.statusDotEnded}`;

                    return (
                        <li key={s.id} className={`${styles.row} ${s.is_running ? styles.rowRunning : ''}`}>
                            <div className={styles.primary}>
                                <div className={styles.metaRow}>
                                    <span className={dotClass} aria-hidden />
                                    <span className={styles.timeStamp}>{formatStartedAt(s.started_at, i18n.language)}</span>
                                    {s.is_running ? (
                                        <span>{t('Running')}</span>
                                    ) : (
                                        <span>
                                            {t('Ran for')} {formatMinutes(s.duration_minutes)}
                                        </span>
                                    )}
                                    {s.ended_reason === 'crashed' && <span>· {t('Process gone before stop')}</span>}
                                </div>
                                <div className={styles.metaRow}>
                                    <span className={`${styles.chip} ${styles.chipMono}`}>
                                        <Terminal size={11} /> {s.cli}
                                    </span>
                                    <span className={`${styles.chip} ${styles.chipMono}`}>
                                        <GitBranch size={11} /> {s.branch_name}
                                    </span>
                                    <span className={styles.chip}>
                                        {t('from')} {s.base_branch === 'unknown' ? t('unknown base') : s.base_branch}
                                    </span>
                                    {s.time_entry && (
                                        <span className={styles.chip}>
                                            <Clock size={11} /> {formatMinutes(s.time_entry.duration_minutes)}
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className={styles.actions}>
                                {s.id === latestClosedId && tmuxAlive && onResume && (
                                    <Button size="sm" onClick={() => onResume(s)} disabled={resuming} aria-label={t('Resume session')}>
                                        <Play size={12} />
                                        {resuming ? t('Resuming…') : t('Resume')}
                                    </Button>
                                )}
                                {/* Edit is only meaningful when the entry has both ends - running
                                   sessions have no end_time yet and editing them would race the
                                   live timer. End the session first, then edit. */}
                                {s.time_entry && !s.is_running && onEditTime && (
                                    <Button size="sm" variant="ghost" onClick={() => onEditTime(s)} aria-label={t('Edit session time')}>
                                        <Pencil size={12} />
                                    </Button>
                                )}
                                <Button size="sm" variant="outline" onClick={() => copyMerge(s)} aria-label={t('Copy merge command')}>
                                    <Copy size={12} />
                                    {copiedId === s.id ? t('Copied!') : t('Copy merge')}
                                </Button>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
