import { ActionMenu, ActionMenuItem } from '@/components/solo/action-menu';
import { BottomAction } from '@/components/solo/bottom-action';
import { BottomSheet } from '@/components/solo/bottom-sheet';
import { HeaderChip, useInHeaderMenu } from '@/components/solo/client-header';
import { FloatingButton } from '@/components/solo/floating-button';
import { KvRow } from '@/components/solo/kv-row';
import { ListCheck, ListRow, ListRowStatic } from '@/components/solo/list-row';
import { LiveLine } from '@/components/solo/live-line';
import { AgentSquareIcon } from '@/components/solo/mobile-icons';
import { BodyLink, SectionAction, SectionHead, SoloButton } from '@/components/solo/primitives';
import { RailLine } from '@/components/solo/rail-line';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { TaskRow } from '@/components/solo/task-row';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { useLiveReload } from '@/hooks/use-live-reload';
import { useNow } from '@/hooks/use-now';
import { useTimerActions } from '@/hooks/use-timer-actions';
import { durationText, minutesSince, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import day from '@/routes/day';
import taskRoutes from '@/routes/tasks';
import { Link, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { hoursNumber, localeOf } from '../client-list/format';
import { CopyContextAction } from '../client-overview/copy-context-action';
import { TaskFormDialog } from '../task-form-dialog';
import styles from './client-tasks.module.css';
import { homePath, initialLane, type TickedRow, withTicked } from './format';
import type { ClientTaskLane, ClientTaskRow, ClientTasks, LaneKey, OrderTasks, RetainerTasks } from './types';

type Translate = ReturnType<typeof useTranslation>['t'];

const LANE_LABEL: Record<LaneKey, string> = {
    doing: 'In progress',
    testing: 'Testing',
    todo: 'To do',
};

/** The phone row that opens a whole lane, Figma "Wszystkie w toku". */
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

const NEW_TASK: Record<LaneKey, { list_name: 'Doing' | 'Testing' | 'To-Do' }> = {
    doing: { list_name: 'Doing' },
    testing: { list_name: 'Testing' },
    todo: { list_name: 'To-Do' },
};

/** Timers an agent starts or stops elsewhere show up live; project is the project page's agent line. */
const LIVE_PROPS = ['tasks', 'client', 'project'];

interface ClientTasksTabProps {
    clientId: number;
    tasks: ClientTasks;
    /** The Dla agentów sheet, opened from the phone ••• menu. */
    agentsOpen?: boolean;
    onAgentsOpenChange?: (open: boolean) => void;
    /** The project page's Projekt column in place of Dla agentów, shown on phones too. */
    side?: ReactNode;
    /** Where a lane's "all" link goes; the client's Zadania tab by default. */
    laneHref?: (lane: LaneKey) => string;
    /** Phones: Nowe zadanie as the screen's bottom action instead of the floating button (Projekt). */
    bottomAction?: boolean;
}

/** Figma: Klient / Zadania, and D8.4 for an order client; phones 331:2124. */
export function ClientTasksTab({
    clientId,
    tasks,
    agentsOpen = false,
    onAgentsOpenChange = () => undefined,
    side,
    laneHref,
    bottomAction = false,
}: ClientTasksTabProps) {
    return (
        <>
            {tasks.mode === 'order' ? (
                <OrderTasksView clientId={clientId} tasks={tasks} />
            ) : (
                <LaneTasksView clientId={clientId} tasks={tasks} side={side} laneHref={laneHref} bottomAction={bottomAction} />
            )}
            <AgentsSheet clientId={clientId} repositories={tasks.repositories} open={agentsOpen} onOpenChange={onAgentsOpenChange} />
        </>
    );
}

/** Vault folder, skills and the rules link in Dla agentów wait for their sources. */
function LaneTasksView({
    clientId,
    tasks,
    side,
    laneHref,
    bottomAction,
}: {
    clientId: number;
    tasks: RetainerTasks;
    side?: ReactNode;
    laneHref?: (lane: LaneKey) => string;
    bottomAction: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [lane, setLane] = useState(() => initialLane(tasks.lanes, tasks.expanded) as LaneKey);
    const [creating, setCreating] = useState(false);
    const [ticked, setTicked] = useState<Record<number, TickedRow>>({});
    const current = tasks.lanes.find((l) => l.key === lane) ?? tasks.lanes[0];
    const panelId = `client-tasks-${clientId}`;
    useLiveReload(LIVE_PROPS, creating);

    const tick = (row: ClientTaskRow, index: number) => {
        if (!current) return;
        const lane = current.key;
        router.post(
            day.tasks.complete(row.task_id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setTicked((all) => ({ ...all, [row.task_id]: { lane, index, row } })),
            },
        );
    };

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

    return (
        <div className={`${splitClass} ${styles.body}`}>
            <section className={`${splitMainClass} ${styles.main}`}>
                <SectionHead
                    kind="body"
                    as="span"
                    className={styles.laneHead}
                    label={
                        <span className={styles.switch} role="tablist" aria-label={t('Task lists')}>
                            {tasks.lanes.map((l) => (
                                <button
                                    key={l.key}
                                    type="button"
                                    role="tab"
                                    id={`${panelId}-${l.key}`}
                                    aria-selected={l.key === current?.key}
                                    aria-controls={panelId}
                                    className={styles.switchTab}
                                    onClick={() => setLane(l.key)}
                                >
                                    {t(LANE_LABEL[l.key])} · {l.count}
                                </button>
                            ))}
                        </span>
                    }
                    action={tasks.projects.length > 0 ? <SectionAction onClick={() => setCreating(true)}>{t('New task')}</SectionAction> : undefined}
                />
                {current && <LanePicker lanes={tasks.lanes} current={current} onPick={setLane} />}
                {current && (
                    <div id={panelId} role="tabpanel" aria-labelledby={`${panelId}-${current.key}`}>
                        <LaneRows
                            clientId={clientId}
                            allHref={laneHref?.(current.key)}
                            lane={current}
                            rows={withTicked(current.key, current.rows, ticked)}
                            locale={localeOf(i18n.language)}
                            onTick={tick}
                            onUntick={untick}
                        />
                    </div>
                )}
            </section>

            {side !== undefined ? (
                <aside className={`${splitSideClass} ${styles.rail} ${styles.sideRail}`}>{side}</aside>
            ) : (
                <aside className={[splitSideClass, styles.rail, styles.agentsRail].join(' ')}>
                    <SectionHead
                        kind="body"
                        label={
                            <span className={styles.railLabel}>
                                <span className={styles.agentMark} aria-hidden="true" />
                                {t('For agents')}
                            </span>
                        }
                    />
                    {tasks.repositories.length === 0 ? (
                        <p className={styles.empty}>{t('No repository paths yet.')}</p>
                    ) : (
                        <ul className={styles.lines}>
                            {tasks.repositories.map((repo) => (
                                <RailLine key={repo.path} title={`${repo.project}: ${repo.path}`}>
                                    {homePath(repo.path)}
                                </RailLine>
                            ))}
                        </ul>
                    )}
                </aside>
            )}

            {tasks.projects.length > 0 && (
                <>
                    {bottomAction ? (
                        <BottomAction phoneOnly>
                            <SoloButton variant="ink" size="md" onClick={() => setCreating(true)}>
                                {t('New task')}
                            </SoloButton>
                        </BottomAction>
                    ) : (
                        <FloatingButton onClick={() => setCreating(true)}>{t('New task')}</FloatingButton>
                    )}
                    <TaskFormDialog
                        open={creating}
                        onOpenChange={setCreating}
                        mode="create"
                        projects={tasks.projects}
                        initial={NEW_TASK[current?.key ?? 'todo']}
                        sheet
                    />
                </>
            )}
        </div>
    );
}

export interface LaneChoice {
    key: LaneKey;
    label: string;
    selected: boolean;
}

/** "W toku · 12" per lane in the switch's order, the current one marked. */
export function laneChoices(lanes: ClientTaskLane[], current: LaneKey, t: Translate): LaneChoice[] {
    return lanes.map((lane) => ({ key: lane.key, label: `${t(LANE_LABEL[lane.key])} · ${lane.count}`, selected: lane.key === current }));
}

/** Figma: Dropdown trigger · lane. The phone's lane switch; the same lane state as the desktop tabs. */
export function LanePicker({ lanes, current, onPick }: { lanes: ClientTaskLane[]; current: ClientTaskLane; onPick: (lane: LaneKey) => void }) {
    const { t } = useTranslation();
    const choices = laneChoices(lanes, current.key, t);
    const label = choices.find((choice) => choice.selected)?.label ?? '';

    return (
        <div className={styles.lanePhone}>
            <ActionMenu
                align="start"
                trigger={
                    <button type="button" className={styles.laneTrigger} aria-label={`${t('Choose a list')}: ${label}`}>
                        {label}
                        <Icon name="chevron-down" size={16} />
                    </button>
                }
            >
                {choices.map((choice) => (
                    <ActionMenuItem
                        key={choice.key}
                        icon={choice.selected ? <Icon name="check" size={16} /> : undefined}
                        aria-current={choice.selected ? 'true' : undefined}
                        onSelect={() => onPick(choice.key)}
                    >
                        {choice.label}
                    </ActionMenuItem>
                ))}
            </ActionMenu>
        </div>
    );
}

function OrderTasksView({ clientId, tasks }: { clientId: number; tasks: OrderTasks }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [creating, setCreating] = useState(false);
    const { pending, start, stop } = useTimerActions();
    const year = new Date().getFullYear();
    useLiveReload(LIVE_PROPS, creating);

    const tick = (row: ClientTaskRow) => router.post(day.tasks.complete(row.task_id).url, {}, { preserveScroll: true });
    const untick = (row: ClientTaskRow) => router.delete(day.tasks.uncomplete(row.task_id).url, { preserveScroll: true });
    const moreHref = (part: 'open' | 'done' | 'time') => clients.show({ client: clientId, tab: 'zadania' }, { query: { wszystkie: part } }).url;
    const moreLink = (part: 'open' | 'done' | 'time', count: number, total: number) =>
        count > 0 && (
            <>
                <div className={styles.more}>
                    <BodyLink href={moreHref(part)} only={['tasks']} preserveScroll preserveState>
                        {t('{{count}} more', { count })} →
                    </BodyLink>
                </div>
                <PhoneAllRow href={moreHref(part)} label={t(ORDER_ALL[part])} count={total} />
            </>
        );

    return (
        <div className={styles.body}>
            <section className={styles.main}>
                <SectionHead
                    kind="body"
                    className={styles.orderHead}
                    label={`${t('Tasks')} · ${tasks.open.count + tasks.done.count}`}
                    action={
                        tasks.projects.length > 0 ? (
                            <SectionAction className={styles.wideAction} onClick={() => setCreating(true)}>
                                {t('New task')}
                            </SectionAction>
                        ) : undefined
                    }
                />
                {tasks.open.rows.length === 0 ? (
                    <p className={styles.empty}>{t('No open tasks.')}</p>
                ) : (
                    <>
                        <ul className={styles.rows}>
                            {tasks.open.rows.map((row) => (
                                <TaskRow
                                    key={row.task_id}
                                    kind="client"
                                    task={{
                                        task_id: row.task_id,
                                        name: row.name,
                                        client: null,
                                        project: null,
                                        done: false,
                                        can_untick: row.can_untick,
                                    }}
                                    onComplete={() => tick(row)}
                                    due={row.due ? shortDate(row.due, locale, year) : ''}
                                    trailing={<RowAction row={row} pending={pending} onStart={start} onStop={stop} />}
                                />
                            ))}
                        </ul>
                        <ul className={styles.phoneRows}>
                            {tasks.open.rows.map((row) => (
                                <PhoneTaskRow
                                    key={row.task_id}
                                    row={row}
                                    sub={row.due ? shortDate(row.due, locale, year) : undefined}
                                    pending={pending}
                                    onTick={() => tick(row)}
                                    onStart={start}
                                    onStop={stop}
                                />
                            ))}
                        </ul>
                    </>
                )}
                {moreLink('open', tasks.open.count - tasks.open.rows.length, tasks.open.count)}
                {tasks.done.count > 0 && (
                    <>
                        <p className={styles.groupLabel}>{`${t('order_done_group')} · ${tasks.done.count}`}</p>
                        <ul className={styles.rows}>
                            {tasks.done.rows.map((row) => (
                                <TaskRow
                                    key={row.task_id}
                                    kind="client"
                                    task={{
                                        task_id: row.task_id,
                                        name: row.name,
                                        client: null,
                                        project: null,
                                        done: true,
                                        can_untick: row.can_untick,
                                    }}
                                    onComplete={() => undefined}
                                    onUncomplete={() => untick(row)}
                                    due={shortDate(row.done_on, locale)}
                                    trailing={<span className={styles.minutes}>{row.minutes > 0 ? durationText(row.minutes, t) : ''}</span>}
                                />
                            ))}
                        </ul>
                        <ul className={styles.phoneRows}>
                            {tasks.done.rows.map((row) => (
                                <PhoneTaskRow
                                    key={row.task_id}
                                    row={row}
                                    sub={shortDate(row.done_on, locale)}
                                    value={row.minutes > 0 ? <span className={styles.phoneCount}>{durationText(row.minutes, t)}</span> : undefined}
                                    pending={pending}
                                    onTick={() => undefined}
                                    onUntick={() => untick(row)}
                                    onStart={start}
                                    onStop={stop}
                                />
                            ))}
                        </ul>
                        {moreLink('done', tasks.done.count - tasks.done.rows.length, tasks.done.count)}
                    </>
                )}
            </section>

            <aside className={styles.rail}>
                <SectionHead kind="body" label={`${t('Time')} · ${hoursNumber(tasks.time.hours, locale)} h`} />
                {tasks.time.rows.length === 0 ? (
                    <p className={styles.empty}>{t('No time logged yet.')}</p>
                ) : (
                    <dl className={styles.lines}>
                        {tasks.time.rows.map((entry) => (
                            <KvRow key={entry.id} layout="split" weight="medium" label={`${shortDate(entry.date, locale)} · ${entry.label}`}>
                                {durationText(entry.minutes, t)}
                            </KvRow>
                        ))}
                    </dl>
                )}
                {moreLink('time', tasks.time.count - tasks.time.rows.length, tasks.time.count)}
            </aside>

            {tasks.projects.length > 0 && (
                <>
                    <FloatingButton onClick={() => setCreating(true)}>{t('New task')}</FloatingButton>
                    <TaskFormDialog
                        open={creating}
                        onOpenChange={setCreating}
                        mode="create"
                        projects={tasks.projects}
                        initial={NEW_TASK.todo}
                        sheet
                    />
                </>
            )}
        </div>
    );
}

interface LaneRowsProps {
    clientId: number;
    lane: ClientTaskLane;
    rows: ClientTaskRow[];
    allHref?: string;
    locale: string;
    onTick: (row: ClientTaskRow, index: number) => void;
    onUntick: (row: ClientTaskRow) => void;
}

function LaneRows({ clientId, lane, rows, allHref: laneAll, locale, onTick, onUntick }: LaneRowsProps) {
    const { t } = useTranslation();
    const { pending, start, stop } = useTimerActions();
    const year = new Date().getFullYear();
    const more = lane.count - lane.rows.length;
    const allHref = laneAll ?? clients.show({ client: clientId, tab: 'zadania' }, { query: { wszystkie: lane.key } }).url;

    if (rows.length === 0) {
        return <p className={styles.empty}>{t('No tasks on this list.')}</p>;
    }

    return (
        <>
            <ul className={styles.rows}>
                {rows.map((row, index) => (
                    <TaskRow
                        key={row.task_id}
                        kind="client"
                        task={{ task_id: row.task_id, name: row.name, client: null, project: null, done: row.done, can_untick: row.can_untick }}
                        onComplete={() => onTick(row, index)}
                        onUncomplete={() => onUntick(row)}
                        due={row.due ? shortDate(row.due, locale, year) : ''}
                        trailing={<RowAction row={row} pending={pending} onStart={start} onStop={stop} />}
                    />
                ))}
            </ul>
            <ul className={styles.phoneRows}>
                {rows.map((row, index) => (
                    <PhoneTaskRow
                        key={row.task_id}
                        row={row}
                        sub={row.due ? shortDate(row.due, locale, year) : undefined}
                        pending={pending}
                        onTick={() => onTick(row, index)}
                        onUntick={() => onUntick(row)}
                        onStart={start}
                        onStop={stop}
                    />
                ))}
            </ul>
            {more > 0 && (
                <>
                    <div className={styles.more}>
                        <BodyLink href={allHref} only={['tasks']} preserveScroll preserveState>
                            {t('{{count}} more', { count: more })} →
                        </BodyLink>
                    </div>
                    <PhoneAllRow href={allHref} label={t(LANE_ALL[lane.key])} count={lane.count} />
                </>
            )}
        </>
    );
}

/** Figma: Row / Wszystkie w toku, the same partial reload as the desktop "N więcej" link. */
function PhoneAllRow({ href, label, count }: { href: string; label: string; count: number }) {
    return (
        <div className={styles.phoneAll}>
            <ListRow
                href={href}
                only={['tasks']}
                preserveScroll
                preserveState
                title={label}
                value={<span className={styles.phoneCount}>{count}</span>}
            />
        </div>
    );
}

interface PhoneTaskRowProps {
    row: ClientTaskRow;
    sub?: string;
    value?: ReactNode;
    pending: boolean;
    onTick: () => void;
    onUntick?: () => void;
    onStart: (taskId: number) => void;
    onStop: (entryId: number) => void;
}

/** Figma: Mobile / List row with the checkbox; the owner's timer shows its elapsed time and Stop, an agent's the live mark. */
export function PhoneTaskRow({ row, sub, value, pending, onTick, onUntick, onStart, onStop }: PhoneTaskRowProps) {
    const { t } = useTranslation();
    const now = useNow(15_000);
    const untick = row.done && row.can_untick && onUntick ? onUntick : undefined;
    const elapsed =
        row.running_id !== null && row.running_since !== null && now !== null ? durationText(minutesSince(row.running_since, now), t) : null;
    const subLine =
        elapsed !== null ? (
            <>
                {sub !== undefined && `${sub} · `}
                <span className={styles.elapsed}>{elapsed}</span>
            </>
        ) : (
            sub
        );

    return (
        <li className={row.done ? styles.phoneDone : undefined}>
            <ListRowStatic
                leading={
                    <ListCheck
                        checked={row.done}
                        onClick={row.done ? untick : onTick}
                        disabled={row.done && !untick}
                        aria-label={row.done ? (untick ? t('Mark as not done') : t('Done')) : t('Mark as done')}
                    />
                }
                title={row.done ? <span className={styles.doneTitle}>{row.name}</span> : row.name}
                titleHref={taskRoutes.show(row.task_id)}
                sub={subLine}
                value={value}
                marks={row.agent_live && !row.done ? { agent: t('{{agent}} working', { agent: 'claude' }) } : undefined}
                trailing={<PhoneRowAction row={row} pending={pending} onStart={onStart} onStop={onStop} />}
            />
        </li>
    );
}

function PhoneRowAction({
    row,
    pending,
    onStart,
    onStop,
}: {
    row: ClientTaskRow;
    pending: boolean;
    onStart: (taskId: number) => void;
    onStop: (entryId: number) => void;
}) {
    const { t } = useTranslation();

    if (row.done) {
        return null;
    }

    if (row.running_id !== null) {
        return (
            <span className={styles.rowActions}>
                <PickToday row={row} />
                <SoloButton variant="stop" size="md" disabled={pending} onClick={() => onStop(row.running_id!)}>
                    {t('Stop')}
                </SoloButton>
            </span>
        );
    }

    if (row.agent_live) {
        return (
            <Link href={taskRoutes.show(row.task_id).url} className={styles.rowChevron} aria-label={t('Open task')}>
                <Icon name="chevron-right" size={16} />
            </Link>
        );
    }

    return (
        <span className={styles.rowActions}>
            <PickToday row={row} />
            <SoloButton variant="outline" size="md" disabled={pending} onClick={() => onStart(row.task_id)}>
                <Icon name="play" size={16} filled />
                {t('Start')}
            </SoloButton>
        </span>
    );
}

/** Figma: Klient · Zadania (923:21831). One click puts the task on today; a picked row says so in stone. */
function PickToday({ row }: { row: ClientTaskRow }) {
    const { t } = useTranslation();

    if (row.picked) {
        return <span className={styles.picked}>{t('Picked')}</span>;
    }

    return (
        <SoloButton
            variant="text"
            size="sm"
            aria-label={`${t('For today')}: ${row.name}`}
            onClick={() => router.post(day.picks.store().url, { task_id: row.task_id }, { preserveScroll: true, only: ['tasks', 'errors'] })}
        >
            {t('For today')}
        </SoloButton>
    );
}

function RowAction({
    row,
    pending,
    onStart,
    onStop,
}: {
    row: ClientTaskRow;
    pending: boolean;
    onStart: (taskId: number) => void;
    onStop: (entryId: number) => void;
}) {
    const { t } = useTranslation();

    if (row.done) {
        return null;
    }

    if (row.running_id !== null) {
        return (
            <span className={styles.rowActions}>
                <PickToday row={row} />
                <SoloButton variant="text" size="sm" disabled={pending} onClick={() => onStop(row.running_id!)}>
                    {t('Stop')}
                </SoloButton>
            </span>
        );
    }

    if (row.agent_live) {
        return (
            <span title={t('{{agent}} working', { agent: 'claude' })}>
                <LiveLine size="sm">claude</LiveLine>
            </span>
        );
    }

    return (
        <span className={styles.rowActions}>
            <PickToday row={row} />
            <SoloButton variant="text" size="sm" disabled={pending} onClick={() => onStart(row.task_id)}>
                {t('Start')}
            </SoloButton>
        </span>
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

export function BriefChipAction({ clientId }: { clientId: number }) {
    const { t } = useTranslation();

    return (
        <HeaderChip onClick={() => copyBrief(clientId, t)} title={t('Copy the command for an agent')} icon={<Icon name="copy" size={16} />}>
            {briefCommand(clientId)}
        </HeaderChip>
    );
}

export type TasksAction = 'brief' | 'agents' | 'copy';

/** Desktop shows the brief chip; the phone ••• menu opens Dla agentów (the brief moves into it) and copies the context (Figma 331:2335). */
export function tasksActionOrder(inMenu: boolean): TasksAction[] {
    return inMenu ? ['agents', 'copy'] : ['brief'];
}

export function TasksActions({ clientId, onOpenAgents }: { clientId: number; onOpenAgents: () => void }) {
    const { t } = useTranslation();

    return (
        <>
            {tasksActionOrder(useInHeaderMenu()).map((action) =>
                action === 'brief' ? (
                    <BriefChipAction key="brief" clientId={clientId} />
                ) : action === 'agents' ? (
                    <ActionMenuItem key="agents" tone="agent" icon={<AgentSquareIcon />} onSelect={onOpenAgents}>
                        {t('For agents')}
                    </ActionMenuItem>
                ) : (
                    <CopyContextAction key="copy" clientId={clientId} />
                ),
            )}
        </>
    );
}

/** Figma: Dla agentów sheet (331:2384). Vault path, skills and Zasady i ścieżki wait for their sources. */
export function AgentsSheet({
    clientId,
    repositories,
    open,
    onOpenChange,
}: {
    clientId: number;
    repositories: { path: string; project: string }[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const wide = useSoloWide();

    return (
        <BottomSheet open={open && !wide} onOpenChange={onOpenChange} title={t('For agents')}>
            {!wide && <AgentsSheetBody clientId={clientId} repositories={repositories} />}
        </BottomSheet>
    );
}

export function AgentsSheetBody({ clientId, repositories }: { clientId: number; repositories: { path: string; project: string }[] }) {
    const { t } = useTranslation();

    return (
        <ul className={styles.agentLines}>
            <li>
                <ListRow
                    leading={
                        <span className={styles.agentSlot}>
                            <span className={styles.agentMarkSmall} aria-hidden="true" />
                        </span>
                    }
                    title={<span className={styles.mono}>{briefCommand(clientId)}</span>}
                    trailing={<Icon name="copy" size={16} />}
                    aria-label={`${t('Copy the command for an agent')}: ${briefCommand(clientId)}`}
                    onClick={() => copyBrief(clientId, t)}
                />
            </li>
            {repositories.length === 0 ? (
                <li className={styles.sheetEmpty}>{t('No repository paths yet.')}</li>
            ) : (
                repositories.map((repo) => (
                    <li key={repo.path} title={`${repo.project}: ${repo.path}`}>
                        <ListRowStatic title={<span className={styles.mono}>{homePath(repo.path)}</span>} />
                    </li>
                ))
            )}
        </ul>
    );
}
