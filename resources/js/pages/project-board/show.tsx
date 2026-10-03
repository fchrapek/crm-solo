import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, FolderKanban, Pencil, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePageActions } from '@/contexts/page-context';
import { listLaneLabel } from '@/lib/list-lane-label';
import { ProjectKanban } from '@/pages/clients/components/project-kanban';
import { type TaskDialogValues, TaskFormDialog } from '@/pages/clients/components/task-form-dialog';
import { ownerFinishLabel, type TaskRowTask } from '@/pages/clients/components/task-row';
import clients from '@/routes/clients';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './show.module.css';

interface ProjectBoardTask extends TaskRowTask {
    description?: string | null;
}

type TFn = ReturnType<typeof useTranslation>['t'];

function priorityLabel(priority: string | null | undefined, t: TFn): string {
    if (priority === 'high') return t('High');
    if (priority === 'medium') return t('Medium');
    if (priority === 'low') return t('Low');
    return '-';
}

interface PageProps extends SharedData {
    client: { id: number; name: string };
    project: {
        id: number;
        name: string;
        is_private: boolean;
        trello_url: string | null;
        custom_lanes: string[];
    };
    tasks: ProjectBoardTask[];
}

/**
 * One project's board. The client Work tab lists projects; drag lives here, on
 * a page wide enough for five-plus lanes without squeezing them under the rest
 * of a tab's content.
 */
export default function ProjectBoardShow() {
    const { t, i18n } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { client, project, tasks } = usePage<PageProps>().props;

    const [taskDialogOpen, setTaskDialogOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<TaskDialogValues | undefined>(undefined);
    const [view, setView] = useState<'board' | 'list'>('board');

    const breadcrumbs: BreadcrumbItem[] = useMemo(
        () => [
            { title: t('Clients'), href: clients.index().url, count: 2 },
            { title: client.name, href: clients.edit(client.id).url },
            { title: project.name, href: '' },
        ],
        [t, client.name, client.id, project.name],
    );

    useEffect(() => setBreadcrumbs(breadcrumbs), [breadcrumbs, setBreadcrumbs]);

    const openCreate = () => {
        setEditingTask(undefined);
        setTaskDialogOpen(true);
    };

    const openEdit = (task: TaskRowTask) => {
        const full = tasks.find((entry) => entry.id === task.id);
        setEditingTask({
            id: task.id,
            name: task.name,
            description: full?.description ?? '',
            list_name: (task.list_name as TaskDialogValues['list_name']) ?? 'To-Do',
            due_date: task.due_date ?? '',
            priority: (task.priority as TaskDialogValues['priority']) ?? '',
            parent_task_id: task.parent_task_id ?? null,
            is_agent_ready: false,
            source: task.source ?? null,
        });
        setTaskDialogOpen(true);
    };

    return (
        <>
            <Head title={`${project.name} - ${client.name}`} />

            <div className={styles.header}>
                <Link href={clients.edit(client.id).url} className={styles.backLink}>
                    <ArrowLeft size={14} />
                    {client.name}
                </Link>
                <div className={styles.titleRow}>
                    <h1 className={styles.title}>
                        <FolderKanban size={20} />
                        {project.name}
                    </h1>
                    {project.is_private && <Badge variant="secondary">{t('Private')}</Badge>}
                    {project.trello_url && (
                        <a
                            href={project.trello_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={styles.boardLink}
                            aria-label={t('View board in Trello')}
                            title={t('View board in Trello')}
                        >
                            <ExternalLink size={14} />
                        </a>
                    )}
                    <div className={styles.actions}>
                        <div className={styles.viewToggle} role="tablist">
                            <button
                                type="button"
                                role="tab"
                                aria-selected={view === 'board'}
                                className={`${styles.viewPill} ${view === 'board' ? styles.viewPillActive : ''}`}
                                onClick={() => setView('board')}
                            >
                                {t('Board')}
                            </button>
                            <button
                                type="button"
                                role="tab"
                                aria-selected={view === 'list'}
                                className={`${styles.viewPill} ${view === 'list' ? styles.viewPillActive : ''}`}
                                onClick={() => setView('list')}
                            >
                                {t('List')}
                            </button>
                        </div>
                        <Button size="sm" variant="outline" onClick={openCreate}>
                            <Plus size={14} className={styles.iconLeading} />
                            {t('New task')}
                        </Button>
                    </div>
                </div>
                {view === 'board' && (
                    <p className={styles.subtitle}>
                        {project.is_private
                            ? t('Drag cards between lanes to move tasks.')
                            : t('Trello-sourced cards are read-only here. Move them on the board in Trello.')}
                    </p>
                )}
            </div>

            {view === 'board' ? (
                <ProjectKanban
                    tasks={tasks}
                    lanes={['Backlog', 'To-Do', 'Doing', 'Testing', 'Done', ...project.custom_lanes]}
                    onEditTask={openEdit}
                />
            ) : (
                /* The same table anatomy as the Leads list - one list style
                   across the app. */
                <div className={styles.taskList}>
                    {tasks.length === 0 ? (
                        <p className={styles.taskListEmpty}>{t('No tasks yet.')}</p>
                    ) : (
                        <table className={styles.table}>
                            <thead>
                                <tr>
                                    <th>{t('Task')}</th>
                                    <th>{t('Status')}</th>
                                    <th>{t('Priority')}</th>
                                    <th>{t('Due')}</th>
                                    <th>{t('Source')}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {tasks.map((task) => {
                                    const overdue = task.is_overdue ?? false;
                                    return (
                                        <tr key={task.id}>
                                            <td>
                                                <Link className={styles.rowLink} href={`/tasks/${task.id}`} prefetch>
                                                    {task.name}
                                                </Link>
                                            </td>
                                            <td className={styles.cellMuted}>{ownerFinishLabel(task, t, i18n.language) ?? listLaneLabel(t, task.list_name)}</td>
                                            <td className={task.priority === 'high' ? styles.priorityHigh : styles.cellMuted}>
                                                {priorityLabel(task.priority, t)}
                                            </td>
                                            <td className={overdue ? styles.dateOverdue : styles.cellMuted}>
                                                {task.due_date ? new Date(task.due_date).toLocaleDateString() : '-'}
                                            </td>
                                            <td className={styles.cellMuted}>{task.source ?? '-'}</td>
                                            <td className={styles.editCell}>
                                                {!task.has_trello_card && (
                                                    <Button variant="ghost" size="icon" aria-label={t('Edit task')} onClick={() => openEdit(task)}>
                                                        <Pencil size={14} />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            )}

            <TaskFormDialog
                open={taskDialogOpen}
                onOpenChange={setTaskDialogOpen}
                projectId={project.id}
                initial={editingTask}
                parentOptions={tasks.map((task) => ({ id: task.id, name: task.name }))}
                mode={editingTask ? 'edit' : 'create'}
            />
        </>
    );
}
