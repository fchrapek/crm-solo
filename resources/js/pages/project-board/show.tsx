import { ActionMenu, ActionMenuItem } from '@/components/solo/action-menu';
import { Chip } from '@/components/solo/chip';
import { HeaderAction, useInHeaderMenu } from '@/components/solo/client-header';
import { IconButton } from '@/components/solo/icon-button';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton } from '@/components/solo/primitives';
import { RecordFrame } from '@/components/solo/record-frame';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileHeader, TileNote, TileRows } from '@/components/solo/tile';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { useNow } from '@/hooks/use-now';
import { capitalize, inMonth, minutesSince } from '@/lib/dates';
import { hoursText, localeOf } from '@/lib/hours-text';
import { type ClientTasks, ClientTasksTab, NewTaskAction, type TaskProject } from '@/pages/clients/components/client-tasks';
import { homePath } from '@/pages/clients/components/client-tasks/format';
import { ConnectTrelloDialog } from '@/pages/clients/components/connect-trello-dialog';
import { ListMappingDialog } from '@/pages/clients/components/list-mapping-dialog';
import { ProjectFormDialog } from '@/pages/clients/components/project-form-dialog';
import { type RepositoryDialogValues, RepositoryFormDialog } from '@/pages/clients/components/repository-form-dialog';
import { projectPlace } from '@/pages/clients/place';
import clients from '@/routes/clients';
import projectBoard from '@/routes/project-board';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './show.module.css';

interface ProjectData {
    id: number;
    name: string;
    description: string | null;
    is_private: boolean;
    trello_url: string | null;
    trello_lists: { id: string; name: string }[];
    trello_list_mapping: Record<string, string>;
    custom_lanes: string[];
    include_in_month_close: boolean;
    backup_path: string | null;
    synced_at: string | null;
    month: string;
    month_minutes: number;
    /** An agent's timer running on one of the project's tasks. */
    live_agent: { agent: string; started_at: string } | null;
    repositories: RepositoryDialogValues[];
}

interface ProjectPageProps {
    client: { id: number; name: string; short_name: string };
    project: ProjectData;
    tasks: ClientTasks;
    /** The client's live projects with their open tasks, and the open tasks of the whole client: the page's pills. */
    projects: TaskProject[];
    open_tasks_count: number;
    trelloEnabled: boolean;
    trelloActions: boolean;
    [key: string]: unknown;
}

type Dialog = 'edit' | 'connect' | 'mapping' | 'disconnect' | 'delete' | null;

/**
 * Figma: Project (1091:23179, phone 1091:23482). The Tasks groups for one project; beside them the project's
 * facts and its repositories. The client's other projects are the page's pills.
 */
export default function ProjectPage() {
    const { client, project, tasks, trelloEnabled, trelloActions } = usePage<SharedData & ProjectPageProps>().props;
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [creating, setCreating] = useState(false);
    const canCreate = tasks.projects.length > 0;
    const tasksHref = clients.show({ client: client.id, tab: 'tasks' }).url;
    const now = useNow(15_000);
    const agent = project.live_agent;
    const agentMinutes = agent !== null && now !== null ? minutesSince(agent.started_at, now) : null;

    return (
        <SoloSurface day="paper">
            <Head title={project.name} />
            <main className={styles.page}>
                <RecordFrame
                    title={project.name}
                    chip={
                        agent !== null && agentMinutes !== null ? (
                            <Chip status="agent">{t('{{agent}} working · {{minutes}} min', { agent: agent.agent, minutes: agentMinutes })}</Chip>
                        ) : undefined
                    }
                    actions={
                        <ProjectActions
                            project={project}
                            trelloEnabled={trelloEnabled}
                            trelloActions={trelloActions}
                            onOpen={setDialog}
                            onCreate={canCreate ? () => setCreating(true) : undefined}
                        />
                    }
                    main={canCreate ? <NewTaskAction onCreate={() => setCreating(true)} /> : undefined}
                />
                <ClientTasksTab
                    clientId={client.id}
                    tasks={tasks}
                    creating={creating}
                    onCreatingChange={setCreating}
                    partHref={(part) => projectBoard.show({ client: client.id, project: project.id }, { query: { expand: part } }).url}
                    side={
                        <>
                            <ProjectTile project={project} onEdit={() => setDialog('edit')} />
                            <RepositoriesTile projectId={project.id} repositories={project.repositories} />
                        </>
                    }
                />
            </main>
            <ProjectFormDialog
                open={dialog === 'edit'}
                onOpenChange={(next) => setDialog(next ? 'edit' : null)}
                initial={{
                    id: project.id,
                    name: project.name,
                    description: project.description,
                    include_in_month_close: project.include_in_month_close,
                    backup_path: project.backup_path,
                }}
            />
            <ConnectTrelloDialog open={dialog === 'connect'} onOpenChange={(next) => setDialog(next ? 'connect' : null)} projectId={project.id} />
            <ListMappingDialog
                open={dialog === 'mapping'}
                onOpenChange={(next) => setDialog(next ? 'mapping' : null)}
                projectId={project.id}
                lists={project.trello_lists}
                mapping={project.trello_list_mapping}
                customLanes={project.custom_lanes}
            />
            <ConfirmDialog
                open={dialog === 'disconnect'}
                onOpenChange={(next) => setDialog(next ? 'disconnect' : null)}
                title={t('Disconnect from Trello?')}
                description={t(
                    'The board will be detached from this project. The project will become private and existing Trello tasks will remain as historical records (read-only, no further sync updates).',
                )}
                confirmLabel={t('Disconnect')}
                variant="destructive"
                onConfirm={() => router.post(`/projects/${project.id}/disconnect-trello`, {}, { preserveScroll: true })}
            />
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(next) => setDialog(next ? 'delete' : null)}
                title={t('Delete project?')}
                description={t('All tasks in this project will be deleted permanently. This cannot be undone.')}
                confirmLabel={t('Delete project')}
                variant="destructive"
                onConfirm={() => router.delete(`/projects/${project.id}`, { onSuccess: () => router.visit(tasksHref) })}
            />
        </SoloSurface>
    );
}

ProjectPage.place = projectPlace satisfies PlaceResolver<ProjectPageProps>;

/**
 * From 768 px: Synchronize, the "more" menu and New task. A phone has New task as its main button, so its
 * "more" menu holds Edit project, Sync Now and the rest.
 */
function ProjectActions({
    project,
    trelloEnabled,
    trelloActions,
    onOpen,
    onCreate,
}: {
    project: ProjectData;
    trelloEnabled: boolean;
    trelloActions: boolean;
    onOpen: (dialog: Dialog) => void;
    onCreate?: () => void;
}) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();
    const sync = () => router.post(`/projects/${project.id}/sync-trello`, {}, { preserveScroll: true });
    const synced = !project.is_private && trelloActions;
    const items = (
        <>
            {inMenu && (
                <HeaderAction icon={<Icon name="pencil" size={16} />} onClick={() => onOpen('edit')}>
                    {t('Edit project')}
                </HeaderAction>
            )}
            {synced && (
                <>
                    {inMenu && (
                        <HeaderAction icon={<Icon name="refresh" size={16} />} onClick={sync}>
                            {t('Sync Now')}
                        </HeaderAction>
                    )}
                    <ActionMenuItem
                        icon={<Icon name="settings" size={16} />}
                        disabled={project.trello_lists.length === 0}
                        onSelect={() => onOpen('mapping')}
                    >
                        {t('Configure list mapping')}
                    </ActionMenuItem>
                    <ActionMenuItem icon={<Icon name="unlink" size={16} />} onSelect={() => onOpen('disconnect')}>
                        {t('Disconnect from Trello')}
                    </ActionMenuItem>
                </>
            )}
            {project.is_private && trelloEnabled && (
                <ActionMenuItem icon={<Icon name="refresh" size={16} />} onSelect={() => onOpen('connect')}>
                    {t('Connect to Trello')}
                </ActionMenuItem>
            )}
            {project.is_private && (
                <ActionMenuItem icon={<Icon name="trash" size={16} />} onSelect={() => onOpen('delete')}>
                    {t('Delete project')}
                </ActionMenuItem>
            )}
        </>
    );
    if (inMenu) return items;
    const hasMenu = synced || project.is_private;

    return (
        <>
            {synced && <HeaderAction onClick={sync}>{t('Synchronize')}</HeaderAction>}
            {hasMenu && <ActionMenu trigger={<IconButton icon="more" look="outline" label={t('Project actions')} />}>{items}</ActionMenu>}
            {onCreate !== undefined && <NewTaskAction onCreate={onCreate} />}
        </>
    );
}

/** "dziś 10:42" for a sync today, the day and the time before that. */
function syncedText(synced: Date, locale: string, t: ReturnType<typeof useTranslation>['t']): string {
    const time = synced.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
    const sameDay = synced.toDateString() === new Date().toDateString();

    return sameDay ? t('today {{time}}', { time }) : `${synced.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' })} ${time}`;
}

/** Figma: Tile · Project. The project's facts: its board, the last sync, this month's hours, the month-close flag and the backup folder. */
function ProjectTile({ project, onEdit }: { project: ProjectData; onEdit: () => void }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const synced = project.synced_at !== null ? new Date(project.synced_at) : null;

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Project')}
                end={
                    <SoloButton variant="secondary" size="control" onClick={onEdit}>
                        {t('Edit')}
                    </SoloButton>
                }
            />
            <dl className={styles.facts}>
                <KvRow layout="tile" label="Trello">
                    {project.trello_url !== null && !project.is_private ? (
                        <a href={project.trello_url} target="_blank" rel="noopener noreferrer">
                            {t('connected')} ↗
                        </a>
                    ) : (
                        t('private')
                    )}
                </KvRow>
                {synced !== null && (
                    <KvRow layout="tile" label={t('Synchronization')}>
                        {syncedText(synced, locale, t)}
                    </KvRow>
                )}
                <KvRow layout="tile" label={t('Hours')}>
                    {t('{{hours}} h {{when}}', { hours: hoursText(project.month_minutes / 60, locale), when: inMonth(project.month, t) })}
                </KvRow>
                <KvRow layout="tile" label={t('In the month close')}>
                    {project.include_in_month_close ? t('Yes') : t('No')}
                </KvRow>
                {project.backup_path && (
                    <KvRow layout="tile" label={t('Backup folder')}>
                        {project.backup_path}
                    </KvRow>
                )}
            </dl>
            {project.description && <TileNote className={styles.description}>{capitalize(project.description)}</TileNote>}
        </Tile>
    );
}

/** Figma: Tile / list · Repositories. The working copies an agent reads from: add, edit and unlink, with the way to the remote. */
function RepositoriesTile({ projectId, repositories }: { projectId: number; repositories: RepositoryDialogValues[] }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<RepositoryDialogValues | 'new' | null>(null);
    const [unlinking, setUnlinking] = useState<number | null>(null);

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Repositories')}
                count={repositories.length}
                end={
                    <SoloButton variant="secondary" size="control" onClick={() => setEditing('new')}>
                        {t('Add')}
                    </SoloButton>
                }
            />
            {repositories.map((repo) => (
                <div key={repo.id} className={styles.repo}>
                    <Row
                        as="div"
                        title={repo.name}
                        meta={repo.provider}
                        action={
                            <SoloButton
                                variant="quiet"
                                size="control"
                                aria-label={`${t('Edit repository')}: ${repo.name}`}
                                onClick={() => setEditing(repo)}
                            >
                                {t('Edit')}
                            </SoloButton>
                        }
                        more={
                            <RowMenu name={repo.name}>
                                <ActionMenuItem onSelect={() => setUnlinking(repo.id)}>{t('Unlink repository')}</ActionMenuItem>
                            </RowMenu>
                        }
                    />
                    {(repo.local_path || repo.remote_url) && (
                        <dl className={styles.repoFacts}>
                            {repo.local_path && (
                                <KvRow layout="tile" label={t('Local path')}>
                                    <span title={repo.local_path}>{homePath(repo.local_path)}</span>
                                </KvRow>
                            )}
                            {repo.remote_url && (
                                <KvRow layout="tile" label={t('Remote URL')}>
                                    {/^https?:\/\//i.test(repo.remote_url) ? (
                                        <a href={repo.remote_url} target="_blank" rel="noopener noreferrer">
                                            {remoteName(repo.remote_url)} ↗
                                        </a>
                                    ) : (
                                        <span title={repo.remote_url}>{remoteName(repo.remote_url)}</span>
                                    )}
                                </KvRow>
                            )}
                        </dl>
                    )}
                </div>
            ))}
            {repositories.length === 0 && (
                <TileRows>
                    <Row rule={false} title={<span className={styles.empty}>{t('No repository paths yet.')}</span>} />
                </TileRows>
            )}
            <RepositoryFormDialog
                open={editing !== null}
                onOpenChange={(next) => !next && setEditing(null)}
                projectId={projectId}
                initial={editing !== null && editing !== 'new' ? editing : undefined}
            />
            <ConfirmDialog
                open={unlinking !== null}
                onOpenChange={(next) => !next && setUnlinking(null)}
                title={t('Unlink repository')}
                description={t('Unlink this repository from the project?')}
                confirmLabel={t('Unlink')}
                variant="destructive"
                onConfirm={() => {
                    if (unlinking !== null) router.delete(`/repositories/${unlinking}`, { preserveScroll: true });
                    setUnlinking(null);
                }}
            />
        </Tile>
    );
}

/** "owner/name" of a remote, as the hosts print it. */
export function remoteName(url: string): string {
    return url
        .replace(/^[a-z+]+:\/\/[^/]+\/|^git@[^:]+:/, '')
        .replace(/\.git$/, '')
        .replace(/\/$/, '');
}
