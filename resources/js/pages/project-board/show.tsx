import { Head, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';

import FlashMessages from '@/components/flash-messages';
import { ActionMenuItem } from '@/components/solo/action-menu';
import { AgentChip, HeaderAction, RecordHeader, useInHeaderMenu } from '@/components/solo/client-header';
import { IconButton } from '@/components/solo/icon-button';
import { KvRow } from '@/components/solo/kv-row';
import { LiveLine } from '@/components/solo/live-line';
import { Masthead } from '@/components/solo/masthead';
import { SectionAction, SectionHead } from '@/components/solo/primitives';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { useNow } from '@/hooks/use-now';
import { capitalize, inMonth, minutesSince } from '@/lib/dates';
import { hoursText, localeOf } from '@/lib/hours-text';
import { type ClientTasks, ClientTasksTab } from '@/pages/clients/components/client-tasks';
import { ConnectTrelloDialog } from '@/pages/clients/components/connect-trello-dialog';
import { ListMappingDialog } from '@/pages/clients/components/list-mapping-dialog';
import { ProjectFormDialog } from '@/pages/clients/components/project-form-dialog';
import clients from '@/routes/clients';
import projectBoard from '@/routes/project-board';
import { type SharedData } from '@/types';
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
}

interface ProjectPageProps {
    client: { id: number; name: string; short_name: string };
    project: ProjectData;
    tasks: ClientTasks;
    trelloEnabled: boolean;
    trelloActions: boolean;
    [key: string]: unknown;
}

type Dialog = 'edit' | 'connect' | 'mapping' | 'disconnect' | 'delete' | null;

/** Figma: Projekt (770:56830; phones 849:21156). The Zadania lanes for one project, the Projekt column beside them. */
export default function ProjectPage() {
    const { client, project, tasks, trelloEnabled, trelloActions } = usePage<SharedData & ProjectPageProps>().props;
    const { t } = useTranslation();
    const [dialog, setDialog] = useState<Dialog>(null);
    const open = (next: Dialog) => () => setDialog(next);
    const back = { href: clients.show({ client: client.id, tab: 'dziennik' }).url, label: client.short_name };
    const actions = <ProjectActions project={project} trelloEnabled={trelloEnabled} trelloActions={trelloActions} onOpen={setDialog} />;
    const now = useNow(15_000);
    const agent = project.live_agent;
    const agentMinutes = agent !== null && now !== null ? minutesSince(agent.started_at, now) : null;

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={project.name} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    <RecordHeader
                        size="poster"
                        back={back}
                        trail={[
                            { label: t('Clients'), href: clients.index().url },
                            { label: client.short_name, href: back.href },
                            { label: project.name },
                        ]}
                        title={project.name}
                        menu={actions}
                        chip={
                            agent !== null && agentMinutes !== null ? (
                                <AgentChip>{t('{{agent}} · {{minutes}} min', { agent: agent.agent, minutes: agentMinutes })}</AgentChip>
                            ) : undefined
                        }
                        live={
                            agent !== null && agentMinutes !== null ? (
                                <LiveLine>{t('{{agent}} working · {{minutes}} min', { agent: agent.agent, minutes: agentMinutes })}</LiveLine>
                            ) : undefined
                        }
                    />
                    <ClientTasksTab
                        clientId={client.id}
                        tasks={tasks}
                        laneHref={(lane) => projectBoard.show({ client: client.id, project: project.id }, { query: { wszystkie: lane } }).url}
                        bottomAction
                        side={<ProjectSection project={project} trelloActions={trelloActions} actions={actions} onEdit={open('edit')} />}
                    />
                </main>
            </div>

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
                onConfirm={() => router.delete(`/projects/${project.id}`, { onSuccess: () => router.visit(back.href) })}
            />
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** Synchronizuj, Mapowanie list, Odłącz or Połącz z Trello, Usuń projekt: menu items in the phone •••, a ••• menu beside Edytuj on desktop. */
function ProjectActions({
    project,
    trelloEnabled,
    trelloActions,
    onOpen,
}: {
    project: ProjectData;
    trelloEnabled: boolean;
    trelloActions: boolean;
    onOpen: (dialog: Dialog) => void;
}) {
    const { t } = useTranslation();
    const inMenu = useInHeaderMenu();
    const sync = () => router.post(`/projects/${project.id}/sync-trello`, {}, { preserveScroll: true });

    const items = (
        <>
            {inMenu && (
                <HeaderAction icon={<Icon name="pencil" size={16} />} onClick={() => onOpen('edit')}>
                    {t('Edit project')}
                </HeaderAction>
            )}
            {!project.is_private && trelloActions && (
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

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton icon="more" size="sm" label={t('Project actions')} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" sideOffset={8} collisionPadding={16}>
                {items}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** "dziś 10:42" for a sync today, the day and the time before that. */
function syncedText(synced: Date, locale: string, t: ReturnType<typeof useTranslation>['t']): string {
    const time = synced.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
    const sameDay = synced.toDateString() === new Date().toDateString();

    return sameDay ? t('today {{time}}', { time }) : `${synced.toLocaleDateString(locale, { day: '2-digit', month: '2-digit' })} ${time}`;
}

function ProjectSection({
    project,
    trelloActions,
    actions,
    onEdit,
}: {
    project: ProjectData;
    trelloActions: boolean;
    actions: ReactNode;
    onEdit: () => void;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const synced = project.synced_at !== null ? new Date(project.synced_at) : null;

    return (
        <>
            <SectionHead
                kind="body"
                label={t('Project')}
                action={
                    <span className={styles.actions}>
                        {!project.is_private && trelloActions && (
                            <SectionAction onClick={() => router.post(`/projects/${project.id}/sync-trello`, {}, { preserveScroll: true })}>
                                {t('Synchronize')}
                            </SectionAction>
                        )}
                        <SectionAction onClick={onEdit}>{t('Edit')}</SectionAction>
                        <span className={styles.wideOnly}>{actions}</span>
                    </span>
                }
            />
            <dl className={styles.facts}>
                <KvRow layout="ledger" label="Trello">
                    {project.trello_url !== null && !project.is_private ? (
                        <a href={project.trello_url} target="_blank" rel="noopener noreferrer" className={styles.link}>
                            {t('connected')} ↗
                        </a>
                    ) : (
                        t('private')
                    )}
                </KvRow>
                {synced !== null && (
                    <KvRow layout="ledger" label={t('Synchronization')}>
                        {syncedText(synced, locale, t)}
                    </KvRow>
                )}
                <KvRow layout="ledger" label={t('Hours')}>
                    {t('{{hours}} h {{when}}', { hours: hoursText(project.month_minutes / 60, locale), when: inMonth(project.month, t) })}
                </KvRow>
            </dl>
            {project.description && <p className={styles.description}>{capitalize(project.description)}</p>}
        </>
    );
}
