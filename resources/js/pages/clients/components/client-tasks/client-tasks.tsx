import { ActionMenuItem } from '@/components/solo/action-menu';
import { HeaderAction, useInHeaderMenu } from '@/components/solo/client-header';
import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { RowMenu } from '@/components/solo/row-menu';
import { GroupHeading, Tile, TileGrid, TileHeader, TileInset, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { Icon } from '@/components/ui/icon';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { durationText, minutesSince, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import day from '@/routes/day';
import projectBoard from '@/routes/project-board';
import taskRoutes from '@/routes/tasks';
import { router } from '@inertiajs/react';
import { createContext, type ReactNode, useContext, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { hoursNumber, localeOf } from '../client-list/format';
import { CopyContextAction } from '../client-overview/copy-context-action';
import { ProjectFormDialog } from '../project-form-dialog';
import { TaskFormDialog } from '../task-form-dialog';
import styles from './client-tasks.module.css';
import { homePath, type TickedRow, withTicked } from './format';
import type { ClientTaskLane, ClientTaskRow, ClientTasks, LaneKey, OrderDoneRow, OrderTasks, RetainerTasks, TaskProject } from './types';

type Translate = ReturnType<typeof useTranslation>['t'];

/** A part of the list the "all" row can open whole. */
export type TasksPart = LaneKey | 'done';

const LANE_LABEL: Record<LaneKey, string> = {
    doing: 'In progress',
    testing: 'Testing',
    todo: 'To do',
};

const LANE_ALL: Record<LaneKey, string> = {
    doing: 'All in progress',
    testing: 'All in testing',
    todo: 'All to do',
};

const ORDER_ALL: Record<'open' | 'done' | 'time', string> = {
    open: 'All open tasks',
    done: 'All done tasks',
    time: 'All time entries',
};

/** A partial reload of the list that keeps the page where it is. */
const KEEP = { only: ['tasks'], preserveScroll: true, preserveState: true };

/** Timers an agent starts or stops elsewhere show up live; project is the project page's agent line. */
const LIVE_PROPS = ['tasks', 'client', 'project'];

interface ClientTasksTabProps {
    clientId: number;
    tasks: ClientTasks;
    /** The Nowe zadanie dialog, opened from the record frame. */
    creating?: boolean;
    onCreatingChange?: (open: boolean) => void;
    /** The project page's Projekt tiles in place of the project list and Dla agentów. */
    side?: ReactNode;
    /** Where a part's "all" row goes; the client's Zadania tab by default. */
    partHref?: (part: TasksPart) => string;
}

/** The rows name their project; a project page, where every row has the same one, turns it off. */
const ProjectNames = createContext(true);

/**
 * Figma: Klient · Zadania (1091:11504, phone 1091:11845). Every lane at once as a group of rows, then what is done;
 * beside them the client's projects and what an agent needs. An order client reads one list and its time.
 */
export function ClientTasksTab({ clientId, tasks, creating = false, onCreatingChange = () => undefined, side, partHref }: ClientTasksTabProps) {
    useLiveReload(LIVE_PROPS, creating);

    return (
        <>
            {tasks.mode === 'order' ? (
                <OrderTasksView clientId={clientId} tasks={tasks} />
            ) : (
                <LaneTasksView clientId={clientId} tasks={tasks} side={side} partHref={partHref} />
            )}
            {tasks.projects.length > 0 && (
                <TaskFormDialog
                    open={creating}
                    onOpenChange={onCreatingChange}
                    mode="create"
                    projects={tasks.projects}
                    initial={{ list_name: 'To-Do', is_reportable: tasks.reportable_by_default }}
                    sheet
                />
            )}
        </>
    );
}

function useTicks() {
    const [ticked, setTicked] = useState<Record<number, TickedRow>>({});
    const tick = (lane: LaneKey, row: ClientTaskRow, index: number) =>
        router.post(
            day.tasks.complete(row.task_id).url,
            {},
            { preserveScroll: true, onSuccess: () => setTicked((all) => ({ ...all, [row.task_id]: { lane, index, row } })) },
        );
    const untick = (row: ClientTaskRow) =>
        router.delete(day.tasks.uncomplete(row.task_id).url, {
            preserveScroll: true,
            onSuccess: () =>
                setTicked((all) => {
                    const rest = { ...all };
                    delete rest[row.task_id];

                    return rest;
                }),
        });

    return { ticked, tick, untick };
}

function LaneTasksView({
    clientId,
    tasks,
    side,
    partHref,
}: {
    clientId: number;
    tasks: RetainerTasks;
    side?: ReactNode;
    partHref?: (part: TasksPart) => string;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { ticked, tick, untick } = useTicks();
    const allHref = partHref ?? ((part: TasksPart) => clients.show({ client: clientId, tab: 'zadania' }, { query: { wszystkie: part } }).url);
    // A task ticked on this visit keeps its place in its lane, so it is not listed a second time under Zrobione.
    const done = tasks.done.rows.filter((row) => ticked[row.task_id] === undefined);

    return (
        <TileGrid>
            <Tile kind="list">
                <ProjectNames.Provider value={side === undefined}>
                    {tasks.lanes.map((lane) => (
                        <TaskGroup
                            key={lane.key}
                            label={t(LANE_LABEL[lane.key])}
                            lane={lane}
                            rows={withTicked(lane.key, lane.rows, ticked)}
                            locale={locale}
                            all={{ label: t(LANE_ALL[lane.key]), href: allHref(lane.key) }}
                            onTick={(row, index) => tick(lane.key, row, index)}
                            onUntick={untick}
                        />
                    ))}
                    {tasks.done.count > 0 && (
                        <DoneGroup
                            label={t('order_done_group')}
                            count={tasks.done.count}
                            rows={done}
                            locale={locale}
                            all={{ label: t(ORDER_ALL.done), href: allHref('done') }}
                            onUntick={untick}
                        />
                    )}
                </ProjectNames.Provider>
            </Tile>
            <TileStack as="aside">
                {side ?? (
                    <>
                        <ProjectsTile clientId={clientId} projects={tasks.projects} />
                        <AgentsTile clientId={clientId} repositories={tasks.repositories} />
                    </>
                )}
            </TileStack>
        </TileGrid>
    );
}

interface AllRow {
    label: string;
    href: string;
}

/** The last row of a group cut short: it opens the whole group and says how many more there are. */
function MoreRow({ all, more }: { all: AllRow; more: number }) {
    const { t } = useTranslation();

    return more > 0 ? <Row title={all.label} href={all.href} visit={KEEP} meta={t('{{count}} more', { count: more })} /> : null;
}

interface TaskGroupProps {
    label: string;
    lane: Pick<ClientTaskLane, 'count' | 'rows'>;
    rows: ClientTaskRow[];
    locale: string;
    all: AllRow;
    /** What an empty group says. */
    empty?: string;
    onTick: (row: ClientTaskRow, index: number) => void;
    onUntick: (row: ClientTaskRow) => void;
}

/** Figma: a lane as a group: its heading with the count, its rows, then the row that opens the rest. */
function TaskGroup({ label, lane, rows, locale, all, empty, onTick, onUntick }: TaskGroupProps) {
    const { t } = useTranslation();
    const { pending, start, stop } = useTimerActions();

    return (
        <>
            <GroupHeading label={label} note={lane.count} />
            {rows.length === 0 ? (
                <TileInset>
                    <TileNote>{empty ?? t('No tasks on this list.')}</TileNote>
                </TileInset>
            ) : (
                <TileRows>
                    {rows.map((row, index) => (
                        <TaskLine
                            key={row.task_id}
                            row={row}
                            locale={locale}
                            pending={pending}
                            onTick={() => onTick(row, index)}
                            onUntick={() => onUntick(row)}
                            onStart={start}
                            onStop={stop}
                        />
                    ))}
                    <MoreRow all={all} more={lane.count - lane.rows.length} />
                </TileRows>
            )}
        </>
    );
}

function DoneGroup({
    label,
    count,
    rows,
    locale,
    all,
    onUntick,
}: {
    label: string;
    count: number;
    rows: OrderDoneRow[];
    locale: string;
    all: AllRow;
    onUntick: (row: ClientTaskRow) => void;
}) {
    const { t } = useTranslation();
    const named = useContext(ProjectNames);

    return (
        <>
            <GroupHeading label={label} note={count} />
            <TileRows>
                {rows.map((row) => (
                    <Row
                        key={row.task_id}
                        check={taskCheck(row, t, undefined, () => onUntick(row))}
                        title={row.name}
                        href={taskRoutes.show(row.task_id)}
                        time={row.minutes > 0 ? durationText(row.minutes, t) : undefined}
                        wideMeta={[shortDate(row.done_on, locale), named ? row.project : null].filter(Boolean).join(' · ')}
                    />
                ))}
                <MoreRow all={all} more={count - rows.length} />
            </TileRows>
        </>
    );
}

/** The tick of a task row: a done task unticks only when the CRM set the tick. */
function taskCheck(row: ClientTaskRow, t: Translate, onTick: (() => void) | undefined, onUntick: () => void) {
    const untick = row.done && row.can_untick ? onUntick : undefined;

    return {
        done: row.done,
        label: `${row.done ? (untick ? t('Mark as not done') : t('Done')) : t('Mark as done')}: ${row.name}`,
        onToggle: row.done ? untick : onTick,
    };
}

/** Whole minutes of the owner's running timer, never negative. */
export function elapsedText(row: Pick<ClientTaskRow, 'running_id' | 'running_since'>, now: number | null, t: Translate): string | null {
    return row.running_id !== null && row.running_since !== null && now !== null ? durationText(minutesSince(row.running_since, now), t) : null;
}

interface TaskLineProps {
    row: ClientTaskRow;
    locale: string;
    pending: boolean;
    onTick: () => void;
    onUntick: () => void;
    onStart: (taskId: number) => void;
    onStop: (entryId: number) => void;
}

/**
 * Figma: Row + more. The owner's timer shows its time and Stop; an agent at work is named and gets no Start;
 * any other open task starts with one click. Na dziś sits in the row's menu and a picked task says so.
 */
export function TaskLine({ row, locale, pending, onTick, onUntick, onStart, onStop }: TaskLineProps) {
    const { t } = useTranslation();
    const now = useNow(15_000);
    const named = useContext(ProjectNames);
    const running = row.running_id !== null && !row.done;
    const elapsed = running ? elapsedText(row, now, t) : null;
    const agent = row.agent_live && !row.done ? t('{{agent}} working', { agent: 'claude' }) : null;
    const due = row.due && !row.done ? shortDate(row.due, locale, new Date().getFullYear()) : undefined;

    return (
        <Row
            check={taskCheck(row, t, onTick, onUntick)}
            title={row.name}
            href={taskRoutes.show(row.task_id)}
            time={running ? (elapsed ?? undefined) : due}
            timeTone={running ? 'over' : 'neutral'}
            timeWide={!running}
            meta={agent}
            wideMeta={[named ? row.project : null, row.picked && !row.done ? t('Picked') : null].filter(Boolean).join(' · ') || undefined}
            action={
                row.done ? undefined : running ? (
                    <SoloButton variant="stop" size="control" disabled={pending} onClick={() => onStop(row.running_id!)}>
                        {t('Stop')}
                    </SoloButton>
                ) : agent !== null ? undefined : (
                    <SoloButton variant="secondary" size="control" disabled={pending} onClick={() => onStart(row.task_id)}>
                        {t('Start')}
                    </SoloButton>
                )
            }
            more={
                row.done ? undefined : (
                    <RowMenu name={row.name}>
                        <ActionMenuItem
                            disabled={row.picked}
                            onSelect={() =>
                                router.post(day.picks.store().url, { task_id: row.task_id }, { preserveScroll: true, only: ['tasks', 'errors'] })
                            }
                        >
                            {row.picked ? t('Picked') : t('For today')}
                        </ActionMenuItem>
                    </RowMenu>
                )
            }
        />
    );
}

/** Figma: Tile / list · Projekty. Every project of the client with its open tasks; a row opens the project's page. */
export function ProjectsTile({ clientId, projects }: { clientId: number; projects: TaskProject[] }) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);

    return (
        <Tile kind="list">
            <TileHeader
                label={t('Projects')}
                count={projects.length}
                end={
                    <SoloButton variant="secondary" size="control" onClick={() => setCreating(true)}>
                        {t('New project')}
                    </SoloButton>
                }
            />
            <TileRows>
                {projects.map((project) => (
                    <Row
                        key={project.id}
                        wrap
                        title={project.name}
                        href={projectBoard.show({ client: clientId, project: project.id })}
                        meta={project.open}
                        wideMeta={project.is_private ? t('private') : 'Trello'}
                    />
                ))}
            </TileRows>
            <ProjectFormDialog open={creating} onOpenChange={setCreating} clientId={clientId} />
        </Tile>
    );
}

export function briefCommand(clientId: number): string {
    return `crm brief ${clientId}`;
}

async function copyBrief(clientId: number, t: Translate) {
    try {
        await navigator.clipboard.writeText(briefCommand(clientId));
        toast.success(t('Command copied.'));
    } catch {
        toast.error(t('Could not copy the command.'));
    }
}

/** Figma: Tile · Dla agentów. Where each project's working copy lives, and the command that hands an agent the client. */
export function AgentsTile({ clientId, repositories }: { clientId: number; repositories: { path: string; project: string }[] }) {
    const { t } = useTranslation();

    return (
        <Tile kind="plain">
            <TileHeader label={t('For agents')} />
            {repositories.length === 0 ? (
                <TileNote>{t('No repository paths yet.')}</TileNote>
            ) : (
                <dl className={styles.lines}>
                    {repositories.map((repo) => (
                        <KvRow key={repo.path} layout="tile" label={repo.project}>
                            <span title={repo.path}>{homePath(repo.path)}</span>
                        </KvRow>
                    ))}
                </dl>
            )}
            <SoloButton variant="secondary" size="control" title={briefCommand(clientId)} onClick={() => copyBrief(clientId, t)}>
                {t('Copy the command for an agent')}
            </SoloButton>
        </Tile>
    );
}

function OrderTasksView({ clientId, tasks }: { clientId: number; tasks: OrderTasks }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const tick = (row: ClientTaskRow) => router.post(day.tasks.complete(row.task_id).url, {}, { preserveScroll: true });
    const untick = (row: ClientTaskRow) => router.delete(day.tasks.uncomplete(row.task_id).url, { preserveScroll: true });
    const allHref = (part: 'open' | 'done' | 'time') => clients.show({ client: clientId, tab: 'zadania' }, { query: { wszystkie: part } }).url;
    const moreTime = tasks.time.count - tasks.time.rows.length;

    return (
        <TileGrid>
            <Tile kind="list">
                <TaskGroup
                    label={t('Tasks')}
                    lane={{ count: tasks.open.count, rows: tasks.open.rows }}
                    rows={tasks.open.rows}
                    locale={locale}
                    all={{ label: t(ORDER_ALL.open), href: allHref('open') }}
                    empty={t('No open tasks.')}
                    onTick={tick}
                    onUntick={untick}
                />
                {tasks.done.count > 0 && (
                    <DoneGroup
                        label={t('order_done_group')}
                        count={tasks.done.count}
                        rows={tasks.done.rows}
                        locale={locale}
                        all={{ label: t(ORDER_ALL.done), href: allHref('done') }}
                        onUntick={untick}
                    />
                )}
            </Tile>
            <TileStack as="aside">
                <Tile kind="plain">
                    <TileHeader label={`${t('Time')} · ${hoursNumber(tasks.time.hours, locale)} h`} />
                    {tasks.time.rows.length === 0 ? (
                        <TileNote>{t('No time logged yet.')}</TileNote>
                    ) : (
                        <dl className={styles.lines}>
                            {tasks.time.rows.map((entry) => (
                                <KvRow key={entry.id} layout="tile" label={`${shortDate(entry.date, locale)} · ${entry.label}`}>
                                    {durationText(entry.minutes, t)}
                                </KvRow>
                            ))}
                        </dl>
                    )}
                    {moreTime > 0 && (
                        <SoloButtonLink href={allHref('time')} {...KEEP} variant="secondary" size="control">
                            {t(ORDER_ALL.time)} · {t('{{count}} more', { count: moreTime })}
                        </SoloButtonLink>
                    )}
                </Tile>
            </TileStack>
        </TileGrid>
    );
}

export type TasksAction = 'brief' | 'new' | 'copy';

/** From 768 px: the agent command, then Nowe zadanie. A phone has Nowe zadanie as its main button and the two copies behind "more". */
export function tasksActionOrder(inMenu: boolean, canCreate: boolean): TasksAction[] {
    const order: TasksAction[] = inMenu ? ['brief', 'copy'] : ['brief', 'new'];

    return canCreate ? order : order.filter((action) => action !== 'new');
}

export function NewTaskAction({ onCreate }: { onCreate: () => void }) {
    const { t } = useTranslation();

    return (
        <HeaderAction primary onClick={onCreate}>
            {t('New task')}
        </HeaderAction>
    );
}

export function TasksActions({ clientId, canCreate, onCreate }: { clientId: number; canCreate: boolean; onCreate: () => void }) {
    const { t } = useTranslation();

    return (
        <>
            {tasksActionOrder(useInHeaderMenu(), canCreate).map((action) =>
                action === 'brief' ? (
                    <HeaderAction
                        key="brief"
                        title={briefCommand(clientId)}
                        icon={<Icon name="copy" size={16} />}
                        onClick={() => copyBrief(clientId, t)}
                    >
                        {t('Copy the command for an agent')}
                    </HeaderAction>
                ) : action === 'new' ? (
                    <NewTaskAction key="new" onCreate={onCreate} />
                ) : (
                    <CopyContextAction key="copy" clientId={clientId} />
                ),
            )}
        </>
    );
}
