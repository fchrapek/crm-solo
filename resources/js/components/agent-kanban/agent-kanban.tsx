import { router } from '@inertiajs/react';
import { Bot, ExternalLink, Pencil, Terminal } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { StartSessionDialog } from '@/components/start-session-dialog';
import { KanbanBoard, KanbanCard, type KanbanCardAction, type KanbanLane } from '@/components/ui/kanban-board';
import { RepositoryFormDialog } from '@/pages/clients/components/repository-form-dialog';
import { TaskFormDialog, type TaskDialogValues } from '@/pages/clients/components/task-form-dialog';

import styles from './agent-kanban.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

export interface AgentKanbanTask {
    id: number;
    name: string;
    description?: string | null;
    type?: 'general' | 'feature' | 'bug' | null;
    issue_url?: string | null;
    list_name?: string | null;
    due_date?: string | null;
    priority?: string | null;
    parent_task_id?: number | null;
    parent_task?: { id: number; name: string } | null;
    child_tasks_count?: number;
    source: string | null;
    agent_lane: string;
    cli?: 'claude' | 'codex' | null;
    session_branch_name?: string | null;
    session_port?: number | null;
    session_attention_at?: string | null;
    project?: { id: number; name: string } | null;
    trello_url: string | null;
    updated_at?: string | null;
}

interface Props {
    tasks: AgentKanbanTask[];
    lanes: string[];
    /** Show project name on each card (useful in client-wide views) */
    showProjectChip?: boolean;
    /** Inertia partial-reload prop names to refresh after a successful drag/edit. Refreshes everything if omitted. */
    reloadOnly?: string[];
}

const LANE_LABEL_KEY = (lane: string) => `agent_lane_${lane}`;

export function AgentKanban({ tasks: initialTasks, lanes, showProjectChip = false, reloadOnly }: Props) {
    const { t } = useTranslation();
    const [tasks, setTasks] = useState<AgentKanbanTask[]>(initialTasks);
    useEffect(() => setTasks(initialTasks), [initialTasks]);

    const [editingTask, setEditingTask] = useState<TaskDialogValues | undefined>(undefined);
    const [editDialogOpen, setEditDialogOpen] = useState(false);
    // Branch picker - one task at a time. Resume of a live session skips this
    // dialog and navigates straight to the task page.
    const [startDialogTask, setStartDialogTask] = useState<AgentKanbanTask | null>(null);
    // RepoFormDialog → re-open StartSessionDialog after save chain.
    const [repoDialogProjectId, setRepoDialogProjectId] = useState<number | null>(null);
    const [retryStartAfterRepoSave, setRetryStartAfterRepoSave] = useState<AgentKanbanTask | null>(null);

    const refreshPageData = () => {
        const only = reloadOnly ? [...reloadOnly, 'flash', 'errors'] : undefined;
        router.reload({ only });
    };

    const parentOptions = useMemo(() => {
        const map = new Map<number, string>();
        for (const task of tasks) {
            map.set(task.id, task.name);
            if (task.parent_task) {
                map.set(task.parent_task.id, task.parent_task.name);
            }
        }
        return Array.from(map, ([id, name]) => ({ id, name }));
    }, [tasks]);

    const kanbanLanes = useMemo<KanbanLane<AgentKanbanTask>[]>(() => {
        return lanes.map((laneId) => ({
            id: laneId,
            label: t(LANE_LABEL_KEY(laneId)),
            items: tasks.filter((task) => (lanes.includes(task.agent_lane) ? task.agent_lane : lanes[0]) === laneId),
        }));
    }, [lanes, tasks, t]);

    const handleMove = (cardId: string | number, _from: string, to: string) => {
        const id = Number(cardId);
        const task = tasks.find((entry) => entry.id === id);
        if (!task) return;

        const previousLane = task.agent_lane;
        setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, agent_lane: to } : entry)));

        fetch(`/tasks/${id}/agent-lane`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ agent_lane: to }),
        })
            .then((res) => {
                if (!res.ok) {
                    setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, agent_lane: previousLane } : entry)));
                    return;
                }
                refreshPageData();
            })
            .catch(() => {
                setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, agent_lane: previousLane } : entry)));
            });
    };

    const openEdit = (task: AgentKanbanTask) => {
        setEditingTask({
            id: task.id,
            name: task.name,
            description: task.description ?? '',
            list_name: (task.list_name as TaskDialogValues['list_name']) ?? 'To-Do',
            due_date: task.due_date ?? '',
            priority: (task.priority as TaskDialogValues['priority']) ?? '',
            parent_task_id: task.parent_task_id ?? null,
            is_agent_ready: true,
            source: task.source ?? null,
        });
        setEditDialogOpen(true);
    };

    const handleStartClick = (task: AgentKanbanTask) => {
        // Resume = session already alive → no picker, go straight to the
        // embedded terminal. The launcher's idempotent return reuses the
        // current port; no new worktree, no branch decision needed.
        if (task.session_port) {
            router.visit(`/tasks/${task.id}`);
            return;
        }
        setStartDialogTask(task);
    };

    return (
        <>
            <div className={styles.boardWrap}>
                <div className={styles.boardHeader}>
                    <Bot size={14} className={styles.boardHeaderIcon} />
                    <span>{t('Agent kanban')}</span>
                </div>
                <KanbanBoard
                    lanes={kanbanLanes}
                    renderCard={(task) => {
                        const isManual = task.source === 'manual';
                        const showSessionButton =
                            task.cli !== null && task.cli !== undefined && (task.agent_lane === 'backlog' || task.agent_lane === 'in_progress');
                        const waitingForUser = task.session_attention_at !== null && task.session_attention_at !== undefined;

                        const chips = [];
                        if (waitingForUser) {
                            chips.push({
                                label: t('Waiting on you'),
                                tone: 'label' as const,
                                icon: <span className={styles.attentionDot} aria-hidden />,
                            });
                        }
                        if (showProjectChip && task.project) {
                            chips.push({ label: task.project.name, tone: 'project' as const });
                        }
                        if (task.parent_task) {
                            chips.push({ label: t('Parent: {{name}}', { name: task.parent_task.name }), tone: 'label' as const });
                        }
                        if ((task.child_tasks_count ?? 0) > 0) {
                            chips.push({ label: t('{{count}} subtasks', { count: task.child_tasks_count }), tone: 'label' as const });
                        }
                        if (task.cli) {
                            chips.push({ label: task.cli, tone: 'label' as const, icon: <Terminal size={10} /> });
                        }
                        if (task.session_branch_name && task.agent_lane !== 'backlog') {
                            chips.push({ label: task.session_branch_name, tone: 'label' as const });
                        }

                        const actions: KanbanCardAction[] = [];
                        if (isManual) {
                            actions.push({ label: t('Edit'), icon: <Pencil size={14} />, onClick: () => openEdit(task) });
                        }
                        if (task.trello_url) {
                            actions.push({
                                label: t('Open in Trello'),
                                icon: <ExternalLink size={14} />,
                                onClick: () => undefined,
                                href: task.trello_url,
                                target: '_blank',
                            });
                        }

                        return (
                            <KanbanCard
                                priority={task.priority as 'high' | 'medium' | 'low' | null | undefined}
                                title={task.name}
                                href={`/tasks/${task.id}`}
                                subtitle={task.description}
                                chips={chips}
                                primaryAction={
                                    showSessionButton
                                        ? {
                                              label: task.session_port ? t('Resume session') : t('Start session'),
                                              icon: <Terminal size={12} />,
                                              onClick: () => handleStartClick(task),
                                          }
                                        : undefined
                                }
                                actions={actions}
                            />
                        );
                    }}
                    getCardId={(task) => task.id}
                    onMove={handleMove}
                    laneMinHeight={300}
                />
            </div>

            <TaskFormDialog
                open={editDialogOpen}
                onOpenChange={(open) => {
                    setEditDialogOpen(open);
                    if (!open) refreshPageData();
                }}
                initial={editingTask}
                parentOptions={parentOptions}
                mode="edit"
            />

            {startDialogTask && (
                <StartSessionDialog
                    open={startDialogTask !== null}
                    onOpenChange={(open) => {
                        if (!open) setStartDialogTask(null);
                    }}
                    taskId={startDialogTask.id}
                    cli={startDialogTask.cli}
                    projectId={startDialogTask.project?.id}
                    onLaunched={() => {
                        const id = startDialogTask.id;
                        setStartDialogTask(null);
                        router.visit(`/tasks/${id}`);
                    }}
                    onRepoMissing={(projectId) => {
                        setRetryStartAfterRepoSave(startDialogTask);
                        setStartDialogTask(null);
                        setRepoDialogProjectId(projectId);
                    }}
                />
            )}

            {repoDialogProjectId !== null && (
                <RepositoryFormDialog
                    open={repoDialogProjectId !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setRepoDialogProjectId(null);
                            // Cancelling the repo dialog cancels the pending
                            // session-start chain too - user can re-click Start
                            // when ready.
                            setRetryStartAfterRepoSave(null);
                        }
                    }}
                    projectId={repoDialogProjectId}
                    onSaved={() => {
                        const taskToRetry = retryStartAfterRepoSave;
                        setRetryStartAfterRepoSave(null);
                        setRepoDialogProjectId(null);
                        if (taskToRetry) {
                            refreshPageData();
                            // Re-open the branch picker - the repo now exists,
                            // user picks a branch, then the actual launch fires.
                            setStartDialogTask(taskToRetry);
                        }
                    }}
                />
            )}
        </>
    );
}
