import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Clock, Copy, GitBranch, Globe, Play, Plus, Square, Terminal } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { ManualTimeEntryDialog } from '@/components/manual-time-entry-dialog';
import { SessionHistory, type TaskSessionSummary } from '@/components/session-history';
import { SessionTimeEditDialog } from '@/components/session-time-edit-dialog';
import { StartSessionDialog } from '@/components/start-session-dialog';
import { TaskPreview } from '@/components/task-preview';
import { TerminalSession } from '@/components/terminal-session';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { RepositoryFormDialog } from '@/pages/clients/components/repository-form-dialog';

import { TaskContext } from './components/task-context';
import styles from './show.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

interface AttachmentSummary {
    id: number;
    url: string;
    original_name: string;
    mime: string;
    size: number;
    label: string | null;
}

interface ChildTask {
    id: number;
    name: string;
    is_completed: boolean;
    agent_lane: string | null;
    cli: 'claude' | 'codex' | null;
}

interface TaskData {
    id: number;
    name: string;
    description: string | null;
    list_name: string | null;
    is_completed: boolean;
    due_date: string | null;
    labels: string[] | null;
    priority: 'high' | 'medium' | 'low' | null;
    parent_task_id: number | null;
    parent_task: { id: number; name: string } | null;
    child_tasks: ChildTask[];
    source: string | null;
    trello_url: string | null;
    created_at: string | null;
    updated_at: string | null;
    project: {
        id: number;
        name: string;
        trello_url: string | null;
        preview_command: string | null;
        preview_working_dir: string | null;
        preview_url: string | null;
    } | null;
    client: { id: number; name: string } | null;
    agent_lane: string | null;
    cli: 'claude' | 'codex' | null;
    session_port: number | null;
    session_branch_name: string | null;
    session_attention_at: string | null;
    session_total_minutes: number;
    session_count: number;
    running_manual_entry: { id: number; start_time: string | null } | null;
    attachments: AttachmentSummary[];
    sessions: TaskSessionSummary[];
    tmux_alive: boolean;
    preview: {
        id: number;
        port: number | null;
        pid: number | null;
        command: string;
        working_dir: string;
        url: string | null;
        started_at: string | null;
    } | null;
}

function formatMinutes(minutes: number): string {
    if (minutes <= 0) return '0m';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function formatStopwatch(totalSeconds: number): string {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const pad = (n: number) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
}

interface Props {
    task: TaskData;
}

export default function Show({ task }: Props) {
    const { t } = useTranslation();
    const [savingCli, setSavingCli] = useState(false);
    const [mergeCopied, setMergeCopied] = useState(false);
    // Branch-picker dialog. Repo dialog state used as a fallback chain when
    // the branch fetch returns repository_missing - save repo, reopen picker.
    const [startDialogOpen, setStartDialogOpen] = useState(false);
    const [repoDialogOpen, setRepoDialogOpen] = useState(false);
    const [retryStartAfterRepoSave, setRetryStartAfterRepoSave] = useState(false);

    const [timeEntryDialogOpen, setTimeEntryDialogOpen] = useState(false);

    // Edit dialog for a session row's TimeEntry. Holds the row the user
    // clicked Edit on so the dialog can prefill from its time_entry.
    const [editingSession, setEditingSession] = useState<TaskSessionSummary | null>(null);

    // Manual stopwatch - runs independently of the terminal session. Live
    // elapsed counter ticks once a second from the server-supplied start_time
    // so the user can see it accumulate without page reloads.
    const [timerProcessing, setTimerProcessing] = useState(false);
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    // Conflict prompt: holds the label list of running entries when the user
    // attempts to start a timer while something else is already tracking.
    // Confirming starts the timer; cancelling drops the attempt.
    const [conflictLabels, setConflictLabels] = useState<string[] | null>(null);
    const runningTimer = task.running_manual_entry;
    useEffect(() => {
        if (!runningTimer || !runningTimer.start_time) {
            setElapsedSeconds(0);
            return;
        }
        const startMs = new Date(runningTimer.start_time).getTime();
        const tick = () => setElapsedSeconds(Math.floor((Date.now() - startMs) / 1000));
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [runningTimer]);

    const performStart = async () => {
        setTimerProcessing(true);
        try {
            const res = await fetch(`/tasks/${task.id}/time-entries/start`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) {
                toast.error(t('Failed to start timer'));
                return;
            }
            router.reload({ only: ['task'] });
        } catch {
            toast.error(t('Failed to start timer'));
        } finally {
            setTimerProcessing(false);
        }
    };

    const startTimer = async () => {
        if (timerProcessing) return;
        try {
            const runningRes = await fetch('/time-entries/running', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (runningRes.ok) {
                const payload = await runningRes.json();
                const others = (payload.entries ?? []).filter((e: { id: number }) => e.id !== runningTimer?.id);
                if (others.length > 0) {
                    setConflictLabels(
                        others.map(
                            (e: { task: { name: string } | null; description: string | null }) => e.task?.name ?? e.description ?? t('(unnamed)'),
                        ),
                    );
                    return;
                }
            }
            await performStart();
        } catch {
            toast.error(t('Failed to start timer'));
        }
    };

    const stopTimer = async () => {
        if (timerProcessing || !runningTimer) return;
        setTimerProcessing(true);
        try {
            const res = await fetch(`/time-entries/${runningTimer.id}/stop`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) {
                toast.error(t('Failed to stop timer'));
                return;
            }
            toast.success(t('Timer stopped'));
            router.reload({ only: ['task'] });
        } catch {
            toast.error(t('Failed to stop timer'));
        } finally {
            setTimerProcessing(false);
        }
    };

    // Visiting the task page = the user is now looking at the session, so
    // clear any pending "waiting on you" attention flag. Fire-and-forget;
    // the broadcast event for any new attention while we're here still fires
    // a toast (a card-level pulse won't show on this page, only on kanban).
    useEffect(() => {
        if (task.session_attention_at === null) return;
        fetch(`/tasks/${task.id}/clear-session-attention`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        }).catch(() => undefined);
    }, [task.id, task.session_attention_at]);

    const updateCli = (value: string) => {
        const nextCli = value === '' ? null : value;
        setSavingCli(true);
        fetch(`/tasks/${task.id}/cli`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ cli: nextCli }),
        })
            .then(() => router.reload({ only: ['task'] }))
            .finally(() => setSavingCli(false));
    };

    const openStartDialog = () => {
        setStartDialogOpen(true);
    };

    const copyMergeCommand = () => {
        if (!task.session_branch_name) return;
        navigator.clipboard.writeText(`git merge ${task.session_branch_name}`);
        setMergeCopied(true);
        setTimeout(() => setMergeCopied(false), 1500);
    };

    const hasSession = task.session_port !== null;
    const canResume = !hasSession && task.tmux_alive;
    const [resuming, setResuming] = useState(false);
    const [killConfirmOpen, setKillConfirmOpen] = useState(false);

    const resumeSession = () => {
        if (resuming) return;
        setResuming(true);
        fetch(`/tasks/${task.id}/resume-session`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(async (res) => {
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    toast.error(body.message ?? t('Failed to resume session'));
                    return;
                }
                router.reload({ only: ['task'] });
            })
            .catch(() => toast.error(t('Failed to resume session')))
            .finally(() => setResuming(false));
    };

    const performKill = () => {
        fetch(`/tasks/${task.id}/kill-session`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(() => {
                toast.success(t('Session killed'));
                router.reload({ only: ['task'] });
            })
            .catch(() => toast.error(t('Failed to kill session')));
    };

    // Per-task preview (project's preview_command run in the task worktree).
    // Lifecycle mirrors session start/stop, surfaced as a button + iframe.
    const [previewStarting, setPreviewStarting] = useState(false);
    const [previewConflict, setPreviewConflict] = useState<{ name: string | null } | null>(null);
    const previewAvailable = task.cli !== null && Boolean(task.project?.preview_command);
    const startPreview = async (force = false) => {
        if (previewStarting) return;
        setPreviewStarting(true);
        try {
            const res = await fetch(`/tasks/${task.id}/preview/start`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ force }),
            });
            const body = await res.json().catch(() => ({}));
            if (res.status === 409 && body.code === 'project_busy') {
                setPreviewConflict({ name: body.conflicting_task?.name ?? null });
                return;
            }
            if (!res.ok) {
                toast.error(body.message ?? t('Failed to start preview'));
                return;
            }
            router.reload({ only: ['task'] });
        } catch {
            toast.error(t('Failed to start preview'));
        } finally {
            setPreviewStarting(false);
        }
    };

    return (
        <>
            <Head title={task.name} />

            <div className={styles.header}>
                {task.client && task.project && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={`/clients/${task.client.id}/edit`}>
                            <ArrowLeft size={16} />
                            {task.client.name} › {task.project.name}
                        </Link>
                    </Button>
                )}
            </div>

            <div className={`${styles.container} ${hasSession ? styles.containerWide : ''}`}>
                <div className={styles.titleBar}>
                    <div className={styles.titleBlock}>
                        <h1 className={`${styles.title} ${task.is_completed ? styles.titleCompleted : ''}`}>{task.name}</h1>
                        <div className={styles.chipRow}>
                            {task.list_name && <span className={styles.chip}>{task.list_name}</span>}
                            {task.agent_lane && <span className={styles.chip}>{t(`agent_lane_${task.agent_lane}`)}</span>}
                            {task.cli && (
                                <span className={`${styles.chip} ${styles.chipMono}`}>
                                    <Terminal size={11} /> {task.cli}
                                </span>
                            )}
                            {task.session_branch_name && (
                                <span className={`${styles.chip} ${styles.chipMono}`}>
                                    <GitBranch size={11} /> {task.session_branch_name}
                                </span>
                            )}
                            {task.session_total_minutes > 0 && (
                                <span className={styles.chip}>
                                    <Clock size={11} />
                                    {task.session_count > 0
                                        ? t('{{count}} sessions · {{total}}', {
                                              count: task.session_count,
                                              total: formatMinutes(task.session_total_minutes),
                                          })
                                        : formatMinutes(task.session_total_minutes)}
                                </span>
                            )}
                            {runningTimer ? (
                                <Button size="sm" variant="outline" onClick={stopTimer} disabled={timerProcessing} aria-label={t('Stop timer')}>
                                    <Square size={12} /> {formatStopwatch(elapsedSeconds)}
                                </Button>
                            ) : (
                                <Button size="sm" variant="ghost" onClick={startTimer} disabled={timerProcessing} aria-label={t('Start timer')}>
                                    <Play size={12} /> {t('Start timer')}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" onClick={() => setTimeEntryDialogOpen(true)}>
                                <Plus size={12} /> {t('Log time')}
                            </Button>
                            {task.parent_task && (
                                <Link href={`/tasks/${task.parent_task.id}`} className={styles.chip}>
                                    {t('Parent: {{name}}', { name: task.parent_task.name })}
                                </Link>
                            )}
                        </div>
                    </div>
                    <div className={styles.actions}>
                        <label className={styles.cliPicker}>
                            {t('Run in:')}
                            <select
                                className={styles.cliSelect}
                                value={task.cli ?? ''}
                                onChange={(e) => updateCli(e.target.value)}
                                disabled={savingCli}
                            >
                                <option value="">{t('None')}</option>
                                <option value="claude">claude</option>
                                <option value="codex">codex</option>
                            </select>
                        </label>
                        {task.cli && canResume && (
                            <Button size="sm" onClick={resumeSession} disabled={resuming}>
                                <Play size={14} />
                                {resuming ? t('Resuming…') : t('Resume session')}
                            </Button>
                        )}
                        {task.cli && !hasSession && !canResume && (
                            <Button size="sm" onClick={openStartDialog}>
                                <Terminal size={14} />
                                {t('Start session')}
                            </Button>
                        )}
                        {task.session_branch_name && (
                            <Button size="sm" variant="outline" onClick={copyMergeCommand}>
                                <Copy size={14} />
                                {mergeCopied ? t('Copied!') : t('Copy merge command')}
                            </Button>
                        )}
                        {task.cli && task.tmux_alive && !hasSession && (
                            <Button size="sm" variant="outline" onClick={() => setKillConfirmOpen(true)}>
                                {t('Kill session')}
                            </Button>
                        )}
                        {previewAvailable && task.preview === null && (
                            <Button size="sm" variant="outline" onClick={() => startPreview(false)} disabled={previewStarting}>
                                <Globe size={14} />
                                {previewStarting ? t('Starting…') : t('Start preview')}
                            </Button>
                        )}
                    </div>
                </div>

                {hasSession ? (
                    <div className={styles.workspace}>
                        <TerminalSession
                            taskId={task.id}
                            port={task.session_port}
                            cli={task.cli}
                            branchName={task.session_branch_name}
                            onStopped={() => router.reload({ only: ['task'] })}
                        />
                        <TaskContext taskId={task.id} description={task.description} attachments={task.attachments} />
                    </div>
                ) : (
                    <TaskContext taskId={task.id} description={task.description} attachments={task.attachments} />
                )}

                {task.preview && task.preview.port !== null && (
                    <TaskPreview
                        taskId={task.id}
                        ttydPort={task.preview.port}
                        command={task.preview.command}
                        workingDir={task.preview.working_dir}
                        url={task.preview.url}
                        onStopped={() => router.reload({ only: ['task'] })}
                    />
                )}

                <SessionHistory
                    sessions={task.sessions}
                    tmuxAlive={task.tmux_alive && !hasSession}
                    onResume={resumeSession}
                    resuming={resuming}
                    onEditTime={(s) => setEditingSession(s)}
                />

                {task.child_tasks.length > 0 && (
                    <section className={styles.section}>
                        <h2 className={styles.sectionTitle}>
                            {t('Child tasks')} ({task.child_tasks.length})
                        </h2>
                        <ul className={styles.childList}>
                            {task.child_tasks.map((c) => (
                                <li key={c.id} className={styles.childItem}>
                                    <Link href={`/tasks/${c.id}`} className={`${styles.childName} ${c.is_completed ? styles.childCompleted : ''}`}>
                                        {c.name}
                                    </Link>
                                    {c.cli && (
                                        <span className={`${styles.chip} ${styles.chipMono}`}>
                                            <Terminal size={10} /> {c.cli}
                                        </span>
                                    )}
                                    {c.agent_lane && <span className={styles.chip}>{t(`agent_lane_${c.agent_lane}`)}</span>}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <ManualTimeEntryDialog
                open={timeEntryDialogOpen}
                onOpenChange={setTimeEntryDialogOpen}
                taskId={task.id}
                onSaved={() => router.reload({ only: ['task'] })}
            />

            <SessionTimeEditDialog
                open={editingSession !== null}
                onOpenChange={(open) => {
                    if (!open) setEditingSession(null);
                }}
                entry={editingSession?.time_entry ?? null}
                onSaved={() => {
                    setEditingSession(null);
                    router.reload({ only: ['task'] });
                }}
            />

            <ConfirmDialog
                open={killConfirmOpen}
                onOpenChange={setKillConfirmOpen}
                title={t('Kill the terminal session?')}
                description={t(
                    'Destroys the tmux session, its scrollback, and the running CLI process. The git branch and worktree are preserved. Use End session if you want to come back to the conversation later.',
                )}
                confirmLabel={t('Kill session')}
                variant="destructive"
                onConfirm={performKill}
            />

            <ConfirmDialog
                open={conflictLabels !== null}
                onOpenChange={(open) => {
                    if (!open) setConflictLabels(null);
                }}
                title={t('Another timer is running')}
                description={
                    conflictLabels
                        ? t('Time is currently being tracked on: {{labels}}. Start a new timer anyway?', { labels: conflictLabels.join(', ') })
                        : ''
                }
                confirmLabel={t('Start anyway')}
                onConfirm={() => {
                    setConflictLabels(null);
                    void performStart();
                }}
            />

            <ConfirmDialog
                open={previewConflict !== null}
                onOpenChange={(open) => {
                    if (!open) setPreviewConflict(null);
                }}
                title={t('Another preview is running on this project')}
                description={
                    previewConflict
                        ? t('"{{name}}" already has a preview running. Only one preview per project at a time. Stop the other and start this one?', {
                              name: previewConflict.name ?? t('Another task'),
                          })
                        : ''
                }
                confirmLabel={t('Stop other, start this')}
                onConfirm={() => {
                    setPreviewConflict(null);
                    void startPreview(true);
                }}
            />

            {task.project && (
                <>
                    <StartSessionDialog
                        open={startDialogOpen}
                        onOpenChange={setStartDialogOpen}
                        taskId={task.id}
                        cli={task.cli}
                        projectId={task.project.id}
                        onLaunched={() => router.reload({ only: ['task'] })}
                        onRepoMissing={(projectId) => {
                            setRetryStartAfterRepoSave(true);
                            setRepoDialogOpen(true);
                            // Carry the project id even though we already have it
                            // from task.project - keeps the contract explicit.
                            void projectId;
                        }}
                    />

                    <RepositoryFormDialog
                        open={repoDialogOpen}
                        onOpenChange={(open) => {
                            setRepoDialogOpen(open);
                            if (!open) setRetryStartAfterRepoSave(false);
                        }}
                        projectId={task.project.id}
                        onSaved={() => {
                            if (retryStartAfterRepoSave) {
                                setRetryStartAfterRepoSave(false);
                                setStartDialogOpen(true);
                            }
                        }}
                    />
                </>
            )}
        </>
    );
}
