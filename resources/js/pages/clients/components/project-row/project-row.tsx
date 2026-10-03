import { Link, router } from '@inertiajs/react';
import { Bot, ExternalLink, Lock, MoreVertical, Pencil, RefreshCw, Settings, Trash, Unlink } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';

import { ConnectTrelloDialog } from '../connect-trello-dialog';
import { ListMappingDialog } from '../list-mapping-dialog';
import { type ProjectDialogValues, ProjectFormDialog } from '../project-form-dialog';
import { ProjectRepoChip, type ProjectRepository } from '../project-repo-chip';

import styles from './project-row.module.css';

export interface ProjectRowProject {
    id: number;
    name: string;
    description?: string | null;
    trello_url: string | null;
    trello_lists?: { id: string; name: string }[];
    trello_list_mapping?: Record<string, string>;
    custom_lanes?: string[];
    is_private: boolean;
    tasks_count: number;
    completed_tasks_count: number;
    active_agent_tasks_count?: number;
    repositories?: ProjectRepository[];
    preview_command?: string | null;
    preview_working_dir?: string | null;
    preview_url?: string | null;
    include_in_month_close?: boolean;
    backup_path?: string | null;
}

interface Props {
    project: ProjectRowProject;
    clientId: number;
    trelloEnabled?: boolean;
    trelloActions?: boolean;
}

/**
 * A project as one row: where it stands, what is running, and the actions that
 * belong to it. The whole row navigates to the project's board page; task
 * creation lives there, not here.
 */
export function ProjectRow({ project, clientId, trelloEnabled = false, trelloActions = true }: Props) {
    const { t } = useTranslation();
    const [projectDialogOpen, setProjectDialogOpen] = useState(false);
    const [mappingDialogOpen, setMappingDialogOpen] = useState(false);
    const [trelloDialogOpen, setTrelloDialogOpen] = useState(false);
    const [disconnectOpen, setDisconnectOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const total = project.tasks_count;
    const completed = project.completed_tasks_count;
    const agentCount = project.active_agent_tasks_count ?? 0;
    const lists = project.trello_lists ?? [];

    const editValues: ProjectDialogValues = {
        id: project.id,
        name: project.name,
        description: project.description ?? null,
        preview_command: project.preview_command ?? null,
        preview_working_dir: project.preview_working_dir ?? null,
        preview_url: project.preview_url ?? null,
        include_in_month_close: project.include_in_month_close ?? false,
        backup_path: project.backup_path ?? null,
    };

    const handleSync = () => {
        setProcessing(true);
        router.post(`/projects/${project.id}/sync-trello`, {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const performDisconnect = () => {
        setProcessing(true);
        router.post(`/projects/${project.id}/disconnect-trello`, {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const performDelete = () => {
        router.delete(`/projects/${project.id}`, { preserveScroll: true });
    };

    return (
        <>
            <div className={styles.row}>
                {/* Mouse target for the whole row; the visible name link below
                    carries keyboard focus, so this one leaves the tab order. */}
                <Link href={`/clients/${clientId}/projects/${project.id}`} className={styles.rowOverlay} tabIndex={-1} aria-hidden="true" prefetch />
                <Link href={`/clients/${clientId}/projects/${project.id}`} className={styles.name} prefetch>
                    {project.name}
                </Link>
                {project.is_private && (
                    <Badge variant="secondary" className={styles.privateBadge}>
                        <Lock size={10} />
                        {t('Private')}
                    </Badge>
                )}
                <span className={styles.count}>
                    {completed}/{total}
                    {total > 0 ? ` (${Math.round((completed / total) * 100)}%)` : ''}
                </span>

                <div className={styles.actions}>
                    <ProjectRepoChip projectId={project.id} repositories={project.repositories ?? []} />
                    {agentCount > 0 && (
                        <Link href={`/clients/${clientId}/projects/${project.id}/agent-board`} className={styles.agentLink}>
                            <Bot size={14} />
                            {agentCount}
                        </Link>
                    )}
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
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label={t('Project actions')} disabled={processing}>
                                <MoreVertical size={14} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => setProjectDialogOpen(true)}>
                                <Pencil size={14} className={styles.dropdownIcon} />
                                {t('Edit project')}
                            </DropdownMenuItem>
                            {project.is_private ? (
                                <>
                                    {trelloEnabled && (
                                        <DropdownMenuItem onClick={() => setTrelloDialogOpen(true)}>
                                            <RefreshCw size={14} className={styles.dropdownIcon} />
                                            {t('Connect to Trello')}
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" onClick={() => setDeleteOpen(true)}>
                                        <Trash size={14} className={styles.dropdownIcon} />
                                        {t('Delete project')}
                                    </DropdownMenuItem>
                                </>
                            ) : (
                                trelloActions && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem onClick={handleSync} disabled={processing}>
                                            <RefreshCw size={14} className={styles.dropdownIcon} />
                                            {t('Sync Now')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem onClick={() => setMappingDialogOpen(true)} disabled={processing || lists.length === 0}>
                                            <Settings size={14} className={styles.dropdownIcon} />
                                            {t('Configure list mapping')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem variant="destructive" onClick={() => setDisconnectOpen(true)} disabled={processing}>
                                            <Unlink size={14} className={styles.dropdownIcon} />
                                            {t('Disconnect from Trello')}
                                        </DropdownMenuItem>
                                    </>
                                )
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <ProjectFormDialog open={projectDialogOpen} onOpenChange={setProjectDialogOpen} initial={editValues} />

            <ConnectTrelloDialog open={trelloDialogOpen} onOpenChange={setTrelloDialogOpen} projectId={project.id} />

            <ListMappingDialog
                open={mappingDialogOpen}
                onOpenChange={setMappingDialogOpen}
                projectId={project.id}
                lists={lists}
                mapping={project.trello_list_mapping ?? {}}
                customLanes={project.custom_lanes ?? []}
            />

            <ConfirmDialog
                open={disconnectOpen}
                onOpenChange={setDisconnectOpen}
                title={t('Disconnect from Trello?')}
                description={t(
                    'The board will be detached from this project. The project will become private and existing Trello tasks will remain as historical records (read-only, no further sync updates).',
                )}
                confirmLabel={t('Disconnect')}
                variant="destructive"
                onConfirm={performDisconnect}
            />

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title={t('Delete project?')}
                description={t('All tasks in this project will be deleted permanently. This cannot be undone.')}
                confirmLabel={t('Delete project')}
                variant="destructive"
                onConfirm={performDelete}
            />
        </>
    );
}
