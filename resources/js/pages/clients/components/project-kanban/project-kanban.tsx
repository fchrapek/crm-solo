import { router } from '@inertiajs/react';
import { Archive, ArchiveRestore, ExternalLink, Eye, EyeOff, Pencil, Terminal, Trash } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { KanbanBoard, KanbanCard, type KanbanCardAction, type KanbanLane } from '@/components/ui/kanban-board';
import { markdownPreview } from '@/lib/markdown-to-text';
import tasksRoute from '@/routes/tasks';

import { type TaskRowTask } from '../task-row';

import styles from './project-kanban.module.css';

const readXsrfToken = (): string => {
    if (typeof document === 'undefined') return '';
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('XSRF-TOKEN='));
    return match ? decodeURIComponent(match.split('=')[1]) : '';
};

const DEFAULT_LANES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'];

interface Props {
    tasks: TaskRowTask[];
    /** Optional canonical lane order (e.g. project.trello_lists). Defaults to manual statuses. */
    lanes?: string[];
    onEditTask?: (task: TaskRowTask) => void;
}

export function ProjectKanban({ tasks: initialTasks, lanes: laneOrder, onEditTask }: Props) {
    const { t } = useTranslation();
    const [tasks, setTasks] = useState<TaskRowTask[]>(initialTasks);
    const [showArchived, setShowArchived] = useState(false);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    useEffect(() => setTasks(initialTasks), [initialTasks]);

    const archivedCount = useMemo(() => tasks.filter((task) => task.archived_at !== null && task.archived_at !== undefined).length, [tasks]);

    const visibleTasks = useMemo(() => (showArchived ? tasks : tasks.filter((task) => !task.archived_at)), [tasks, showArchived]);

    const resolvedLanes = useMemo(() => {
        const base = laneOrder && laneOrder.length > 0 ? [...laneOrder] : [...DEFAULT_LANES];
        for (const task of visibleTasks) {
            if (task.list_name && !base.includes(task.list_name)) {
                base.push(task.list_name);
            }
        }
        return base;
    }, [laneOrder, visibleTasks]);

    const lanes = useMemo<KanbanLane<TaskRowTask>[]>(() => {
        return resolvedLanes.map((laneName) => {
            const key = `list_lane_${laneName}`;
            const translated = t(key);
            const label = translated === key ? laneName : translated;
            return {
                id: laneName,
                label,
                items: visibleTasks.filter((task) => (task.list_name ?? DEFAULT_LANES[0]) === laneName),
            };
        });
    }, [resolvedLanes, visibleTasks, t]);

    const handleMove = (cardId: string | number, _from: string, to: string) => {
        const id = Number(cardId);
        const task = tasks.find((entry) => entry.id === id);
        if (!task || task.source === 'trello') return;

        const previous = task.list_name;
        setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, list_name: to, is_completed: to === 'Done' } : entry)));

        fetch(`/tasks/${id}/list-name`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ list_name: to }),
        })
            .then((res) => {
                if (!res.ok) {
                    setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, list_name: previous ?? null } : entry)));
                    return;
                }
                router.reload({ only: ['tasks'] });
            })
            .catch(() => {
                setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, list_name: previous ?? null } : entry)));
            });
    };

    const performArchive = (task: TaskRowTask, archive: boolean) => {
        const id = task.id;
        const previous = task.archived_at ?? null;
        const next = archive ? new Date().toISOString() : null;

        setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, archived_at: next } : entry)));

        fetch(`/tasks/${id}/archived`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ archived: archive }),
        })
            .then((res) => {
                if (!res.ok) {
                    setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, archived_at: previous } : entry)));
                    return;
                }
                router.reload({ only: ['tasks'] });
            })
            .catch(() => {
                setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, archived_at: previous } : entry)));
            });
    };

    const performDelete = () => {
        if (deleteId === null) return;
        router.delete(`/tasks/${deleteId}`, { preserveScroll: true });
    };

    const setCli = (task: TaskRowTask, cli: 'claude' | 'codex' | null) => {
        const id = task.id;
        const previous = task.cli ?? null;
        setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, cli } : entry)));
        fetch(`/tasks/${id}/cli`, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': readXsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ cli }),
        })
            .then((res) => {
                if (!res.ok) {
                    setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, cli: previous } : entry)));
                    return;
                }
                router.reload({ only: ['tasks'] });
            })
            .catch(() => {
                setTasks((prev) => prev.map((entry) => (entry.id === id ? { ...entry, cli: previous } : entry)));
            });
    };

    return (
        <>
            <KanbanBoard
                lanes={lanes}
                renderCard={(task) => {
                    const isArchived = !!task.archived_at;
                    const isOverdue = task.is_overdue ?? (task.due_date ? new Date(task.due_date) < new Date() && !task.is_completed : false);

                    const actions: KanbanCardAction[] = [];
                    if (task.source !== 'trello' && onEditTask && !isArchived) {
                        actions.push({ label: t('Edit'), icon: <Pencil size={14} />, onClick: () => onEditTask(task) });
                    }
                    if (!isArchived && task.cli === null) {
                        actions.push({
                            label: t('Send to agent kanban'),
                            icon: <Terminal size={14} />,
                            onClick: () => setCli(task, 'claude'),
                        });
                    }
                    if (!isArchived && task.cli !== null && task.cli !== undefined) {
                        actions.push({
                            label: t('Remove from agent kanban'),
                            icon: <Terminal size={14} />,
                            onClick: () => setCli(task, null),
                        });
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
                    const isTrelloSource = task.source === 'trello';
                    if (!isTrelloSource && !isArchived) {
                        actions.push({
                            label: t('Archive'),
                            icon: <Archive size={14} />,
                            onClick: () => performArchive(task, true),
                        });
                    }
                    if (!isTrelloSource && isArchived) {
                        actions.push({
                            label: t('Restore'),
                            icon: <ArchiveRestore size={14} />,
                            onClick: () => performArchive(task, false),
                        });
                    }
                    if (!isTrelloSource) {
                        actions.push({
                            label: t('Delete'),
                            icon: <Trash size={14} />,
                            onClick: () => setDeleteId(task.id),
                            destructive: true,
                        });
                    }

                    const chips: { label: string; tone: 'label' | 'trello' }[] = [];
                    if (isArchived) {
                        chips.push({ label: t('Archived'), tone: 'label' });
                    }
                    if (task.trello_url) {
                        chips.push({ label: t('Trello'), tone: 'trello' });
                    }
                    if (task.parent_task) {
                        chips.push({ label: t('Parent: {{name}}', { name: task.parent_task.name }), tone: 'label' });
                    }
                    if ((task.child_tasks_count ?? 0) > 0) {
                        chips.push({ label: t('{{count}} subtasks', { count: task.child_tasks_count }), tone: 'label' });
                    }
                    if (task.cli) {
                        chips.push({ label: task.cli, tone: 'label' });
                    }

                    return (
                        <KanbanCard
                            priority={task.priority as 'high' | 'medium' | 'low' | null | undefined}
                            title={task.name}
                            subtitle={markdownPreview(task.description)}
                            href={tasksRoute.show(task.id).url}
                            isCompleted={task.is_completed || isArchived}
                            dueDate={task.due_date}
                            isOverdue={isOverdue}
                            recurrencePeriodDays={task.recurrence_period_days}
                            labels={task.labels ?? []}
                            chips={chips.length > 0 ? chips : undefined}
                            actions={actions}
                        />
                    );
                }}
                getCardId={(task) => task.id}
                isCardDraggable={(task) => task.source !== 'trello' && !task.archived_at}
                onMove={handleMove}
            />

            {archivedCount > 0 && (
                <button type="button" className={styles.toggleArchived} onClick={() => setShowArchived((v) => !v)}>
                    {showArchived ? <EyeOff size={12} /> : <Eye size={12} />}
                    {showArchived
                        ? t('Hide archived ({{count}})', { count: archivedCount })
                        : t('Show archived ({{count}})', { count: archivedCount })}
                </button>
            )}

            <ConfirmDialog
                open={deleteId !== null}
                onOpenChange={(open) => {
                    if (!open) setDeleteId(null);
                }}
                title={t('Delete task')}
                description={t('Delete this task?')}
                confirmLabel={t('Delete')}
                variant="destructive"
                onConfirm={performDelete}
            />
        </>
    );
}
