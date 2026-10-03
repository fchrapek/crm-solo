import { router } from '@inertiajs/react';
import { ExternalLink, FolderGit2, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useHostExec } from '@/hooks/use-host-exec';

import { RepositoryFormDialog, type RepositoryDialogValues } from '../repository-form-dialog';

import styles from './project-repo-chip.module.css';

export interface ProjectRepository {
    id: number;
    name: string;
    local_path: string | null;
    remote_url: string | null;
    provider: string;
}

interface Props {
    projectId: number;
    repositories: ProjectRepository[];
}

/**
 * Compact "📁 repo-name" / "+ repo" badge rendered in the project header.
 * Visually consistent with other badges (e.g. the PRYWATNY tag).
 * Click opens a popover with the repo list + add/edit/unlink actions.
 */
export function ProjectRepoChip({ projectId, repositories }: Props) {
    const { t } = useTranslation();
    const hostExec = useHostExec();
    const [popoverOpen, setPopoverOpen] = useState(false);
    const [repoDialogOpen, setRepoDialogOpen] = useState(false);
    const [editingRepo, setEditingRepo] = useState<RepositoryDialogValues | undefined>(undefined);
    const [unlinkRepoId, setUnlinkRepoId] = useState<number | null>(null);

    const hasRepos = repositories.length > 0;

    const openCreate = () => {
        setEditingRepo(undefined);
        setPopoverOpen(false);
        setRepoDialogOpen(true);
    };

    const openEdit = (repo: ProjectRepository) => {
        setEditingRepo({
            id: repo.id,
            name: repo.name,
            local_path: repo.local_path,
            remote_url: repo.remote_url,
            provider: repo.provider,
        });
        setPopoverOpen(false);
        setRepoDialogOpen(true);
    };

    const performRemove = () => {
        if (unlinkRepoId === null) return;
        router.delete(`/repositories/${unlinkRepoId}`, { preserveScroll: true });
    };

    // 0 repos → outline badge that opens the create dialog directly
    if (!hasRepos) {
        if (!hostExec) return null;

        return (
            <>
                <Badge variant="outline" asChild>
                    <button type="button" className={styles.chipButton} onClick={openCreate} aria-label={t('Add repository')}>
                        <Plus size={12} />
                        {t('Repository')}
                    </button>
                </Badge>

                <RepositoryFormDialog open={repoDialogOpen} onOpenChange={setRepoDialogOpen} projectId={projectId} initial={editingRepo} />
            </>
        );
    }

    const chipLabel = repositories.length === 1 ? repositories[0].name : t('repository_count', { count: repositories.length });

    return (
        <>
            <Popover open={popoverOpen} onOpenChange={setPopoverOpen}>
                <PopoverTrigger asChild>
                    <Badge variant="secondary" asChild>
                        <button type="button" className={styles.chipButton} aria-label={t('Manage repositories')}>
                            <FolderGit2 size={12} />
                            <span className={styles.chipLabel}>{chipLabel}</span>
                        </button>
                    </Badge>
                </PopoverTrigger>
                <PopoverContent align="end" className={styles.popoverContent}>
                    <div className={styles.popoverHeader}>{t('Repositories')}</div>
                    <div className={styles.repoList}>
                        {repositories.map((repo) => (
                            <div key={repo.id} className={styles.repoRow}>
                                <FolderGit2 size={14} className={styles.repoIcon} />
                                <div className={styles.repoMeta}>
                                    <span className={styles.repoName}>{repo.name}</span>
                                    {repo.local_path && <code className={styles.repoPath}>{repo.local_path}</code>}
                                </div>
                                {repo.remote_url && (
                                    <a
                                        href={repo.remote_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={styles.repoExternal}
                                        aria-label={t('Open remote')}
                                    >
                                        <ExternalLink size={12} />
                                    </a>
                                )}
                                {hostExec && (
                                    <button type="button" onClick={() => openEdit(repo)} aria-label={t('Edit repository')} className={styles.iconButton}>
                                        <Pencil size={12} />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPopoverOpen(false);
                                        setUnlinkRepoId(repo.id);
                                    }}
                                    aria-label={t('Unlink repository')}
                                    className={styles.iconButton}
                                >
                                    <X size={12} />
                                </button>
                            </div>
                        ))}
                    </div>
                    {hostExec && (
                        <div className={styles.popoverFooter}>
                            <Button size="sm" variant="ghost" onClick={openCreate}>
                                <Plus size={12} className={styles.iconLeading} />
                                {t('Add repository')}
                            </Button>
                        </div>
                    )}
                </PopoverContent>
            </Popover>

            <RepositoryFormDialog open={repoDialogOpen} onOpenChange={setRepoDialogOpen} projectId={projectId} initial={editingRepo} />

            <ConfirmDialog
                open={unlinkRepoId !== null}
                onOpenChange={(open) => {
                    if (!open) setUnlinkRepoId(null);
                }}
                title={t('Unlink repository')}
                description={t('Unlink this repository from the project?')}
                confirmLabel={t('Unlink')}
                variant="destructive"
                onConfirm={performRemove}
            />
        </>
    );
}
