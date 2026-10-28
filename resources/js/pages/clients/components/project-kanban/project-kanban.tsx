import { Icon } from '@/components/ui/icon';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { KanbanBoard, KanbanCard, type KanbanCardAction, type KanbanLane } from '@/components/ui/kanban-board';
import { jsonRequest, JsonRequestError } from '@/lib/json-request';
import { listLaneLabel } from '@/lib/list-lane-label';
import { markdownPreview } from '@/lib/markdown-to-text';
import tasksRoute from '@/routes/tasks';

import { ownerFinishLabel, type TaskRowTask } from '../task-row';

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
    const { t, i18n } = useTranslation();
    const [tasks, setTasks] = useState<TaskRowTask[]>(initialTasks);
    const [showArchived, setShowArchived] = useState(false);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [archiveOffer, setArchiveOffer] = useState<{ task: TaskRowTask; reason: string } | null>(null);
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
            return {
                id: laneName,
                label: listLaneLabel(t, laneName),
                items: visibleTasks.filter((task) => (task.list_name ?? DEFAULT_LANES[0]) === laneName),
            };
        });
    }, [resolvedLanes, visibleTasks, t]);

    const handleMove = (cardId: string | number, _from: string, to: string) => {
        const id = Number(cardId);
        const task = tasks.find((entry) => entry.id === id);
        if (!task || task.has_trello_card) return;

        const previous = task;
        const restore = () => setTasks((prev) => prev.map((entry) => (entry.id === id ? previous : entry)));
        setTasks((prev) =>
            prev.map((entry) =>
                entry.id === id
                    ? {
                          ...entry,
                          list_name: to,
                          is_completed: to === 'Done',
                          finished_at: to === 'Done' ? (entry.finished_at ?? new Date().toISOString()) : null,
                      }
                    : entry,
            ),
        );

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
            .then(async (res) => {
                if (!res.ok) {
                    restore();
                    return;
                }
                // The server's answer replaces the guess: the lane, completion and finish date it actually stored.
                const saved = (await res.json()) as Pick<TaskRowTask, 'list_name' | 'is_completed' | 'finished_at'>;
                setTasks((prev) =>
                    prev.map((entry) =>
                        entry.id === id
                            ? { ...entry, list_name: saved.list_name, is_completed: saved.is_completed, finished_at: saved.finished_at ?? null }
                            : entry,
                    ),
                );
                router.reload({ only: ['tasks'] });
            })
            .catch(restore);
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
        const task = tasks.find((entry) => entry.id === deleteId);
        if (!task) return;
        jsonRequest(tasksRoute.destroy(task.id).url, 'DELETE')
            .then(() => {
                toast.success(t('Task deleted.'));
                router.reload({ only: ['tasks'] });
            })
            .catch((error: unknown) => {
                const reason = error instanceof JsonRequestError ? error.errors.archive_instead?.[0] : undefined;
                if (reason) {
                    setArchiveOffer({ task, reason });
                } else {
                    toast.error(error instanceof Error ? error.message : String(error));
                }
            });
    };

    return (
        <>
            <KanbanBoard
                lanes={lanes}
                renderCard={(task) => {
                    const isArchived = !!task.archived_at;
                    // Overdue is decided on the server, in the owner's calendar.
                    const isOverdue = task.is_overdue ?? false;

                    const actions: KanbanCardAction[] = [];
                    if (!task.has_trello_card && onEditTask && !isArchived) {
                        actions.push({ label: t('Edit'), icon: <Icon name="pencil" size={16} />, onClick: () => onEditTask(task) });
                    }
                    if (task.trello_url) {
                        actions.push({
                            label: t('Open in Trello'),
                            icon: <Icon name="external-link" size={16} />,
                            onClick: () => undefined,
                            href: task.trello_url,
                            target: '_blank',
                        });
                    }
                    const isTrelloSource = !!task.has_trello_card;
                    if (!isTrelloSource && !isArchived) {
                        actions.push({
                            label: t('Archive'),
                            icon: <Icon name="archive" size={16} />,
                            onClick: () => performArchive(task, true),
                        });
                    }
                    if (!isTrelloSource && isArchived) {
                        actions.push({
                            label: t('Restore'),
                            icon: <Icon name="restore" size={16} />,
                            onClick: () => performArchive(task, false),
                        });
                    }
                    if (!isTrelloSource) {
                        actions.push({
                            label: t('Delete'),
                            icon: <Icon name="trash" size={16} />,
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
                        chips.push({ label: t('subtask_count', { count: task.child_tasks_count }), tone: 'label' });
                    }
                    const finishLabel = ownerFinishLabel(task, t, i18n.language);
                    if (finishLabel) {
                        chips.push({ label: finishLabel, tone: 'label' });
                    }

                    return (
                        <KanbanCard
                            priority={task.priority as 'high' | 'medium' | 'low' | null | undefined}
                            title={task.name}
                            subtitle={markdownPreview(task.description)}
                            href={tasksRoute.show(task.id).url}
                            isCompleted={task.is_completed || !!task.finished_at || isArchived}
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
                isCardDraggable={(task) => !task.has_trello_card && !task.archived_at}
                onMove={handleMove}
            />

            {archivedCount > 0 && (
                <button type="button" className={styles.toggleArchived} onClick={() => setShowArchived((v) => !v)}>
                    {showArchived ? <Icon name="eye-off" size={16} /> : <Icon name="eye" size={16} />}
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

            <ConfirmDialog
                open={archiveOffer !== null}
                onOpenChange={(open) => {
                    if (!open) setArchiveOffer(null);
                }}
                title={t('Delete task')}
                description={archiveOffer?.reason}
                confirmLabel={t('Archive task')}
                onConfirm={() => archiveOffer && performArchive(archiveOffer.task, true)}
            />
        </>
    );
}
