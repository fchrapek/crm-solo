export interface ChildTask {
    id: number;
    name: string;
    is_completed: boolean;
    finished_at: string | null;
    /** Finished by the owner or completed on its board. */
    is_done: boolean;
}

export interface TaskData {
    id: number;
    name: string;
    description: string | null;
    /** The description as a card's markup normalises to Markdown; the preview renders this. */
    description_markdown: string;
    list_name: string | null;
    is_completed: boolean;
    finished_at: string | null;
    is_done: boolean;
    can_unfinish: boolean;
    is_archived: boolean;
    /** One of today's picks already. */
    picked_today: boolean;
    due_date: string | null;
    due_day: string | null;
    is_overdue: boolean;
    parent_task: { id: number; name: string } | null;
    child_tasks: ChildTask[];
    has_trello_card: boolean;
    trello_url: string | null;
    project: { id: number; name: string; trello_url: string | null } | null;
    client: { id: number; name: string; short_name: string } | null;
}

export interface TaskTime {
    today_minutes: number;
    total_minutes: number;
    /** Local midnight as an instant: a running timer counts toward today from here. */
    day_start: string;
    own_running: { id: number; started_at: string | null } | null;
    /** Start instants of every running timer on the task, the agents' included. */
    running: string[];
    /** The newest running timer's start on the display timezone's clock, "11:34". */
    running_since: string | null;
}

export type ActorKind = 'agent' | 'owner' | 'person';

export interface Actor {
    kind: ActorKind;
    name: string | null;
}

export interface Stamp {
    at: string;
    /** Local date and time in the display timezone. */
    day: string;
    time: string;
}

export type BriefField = 'where' | 'done_when' | 'constraints' | 'notes';

export interface TaskBriefData {
    fields: Record<BriefField, string | null>;
    filled: number;
    last_change: (Stamp & { actor: Actor }) | null;
}

export interface CardChecklist {
    name: string;
    items: { name: string; done: boolean }[];
}

export interface TaskCard {
    fetched_at: string | null;
    error: string | null;
    checklists: CardChecklist[];
    done: number;
    total: number;
}

export interface ResourceFile {
    id: number;
    name: string;
    url: string;
    mime: string;
    size: number;
    from: 'trello' | 'upload';
    is_image: boolean;
}

export interface ResourceLink {
    id: number | null;
    url: string;
    label: string | null;
    from: 'crm' | 'trello';
}

export interface TaskResources {
    files: ResourceFile[];
    images: ResourceFile[];
    links: ResourceLink[];
}

export type ActivityKind = 'time' | 'note' | 'brief' | 'task' | 'task_created' | 'finished' | 'reopened';

export interface ActivityRow extends Stamp {
    key: string;
    kind: ActivityKind;
    actor: Actor;
    minutes: number | null;
    text: string | null;
    fields: string[];
}

export interface TaskActivityPage {
    /** Newest first: the preview, or every row with ?historia. */
    rows: ActivityRow[];
    more: boolean;
}

export interface LiveAgent {
    entry_id: number;
    agent: string;
    started_at: string;
    time: string;
    description: string | null;
}

export interface TaskClock {
    /** Today in the display timezone (YYYY-MM-DD). */
    today: string;
    now: string;
}

export interface TaskPageProps {
    task: TaskData;
    time: TaskTime;
    brief: TaskBriefData;
    card: TaskCard | null;
    resources: TaskResources;
    activity: TaskActivityPage;
    live: LiveAgent | null;
    clock: TaskClock;
    [key: string]: unknown;
}

/** The props that change while the page is open: timers, agent writes, the card cache and the day. */
export const LIVE_PROPS: string[] = ['task', 'time', 'brief', 'card', 'resources', 'activity', 'live', 'clock'] satisfies (keyof TaskPageProps)[];
