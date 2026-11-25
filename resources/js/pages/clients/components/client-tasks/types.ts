export type LaneKey = 'doing' | 'testing' | 'todo' | 'other';

export interface ClientTaskRow {
    task_id: number;
    name: string;
    in_progress?: boolean;
    /** The project the task belongs to. */
    project?: string | null;
    done: boolean;
    can_untick: boolean;
    /** Y-m-d in the display timezone. */
    due: string | null;
    /** The task's list on its board. */
    list?: string | null;
    /** Every finished entry on the task, any day. */
    minutes?: number;
    /** The owner's running timer on this task, the one Stop closes. */
    running_id: number | null;
    /** ISO start of that timer, for the elapsed time phones show. */
    running_since: string | null;
    agent_live: boolean;
    /** One of today's picks already. */
    picked: boolean;
}

/** A live project of the client with its open tasks; private means no Trello board behind it. */
export interface TaskProject {
    id: number;
    name: string;
    open: number;
    is_private: boolean;
    /** In the month close, while the account has the module on. */
    in_month_close: boolean;
    repositories: number;
}

export interface ClientTaskLane {
    key: LaneKey;
    count: number;
    rows: ClientTaskRow[];
}

export interface RetainerTasks {
    mode: 'retainer';
    /** The lane sent whole; every other lane carries its first five rows. */
    expanded: LaneKey | 'done' | null;
    lanes: ClientTaskLane[];
    /** What is done, newest first: the first five rows unless expanded is done. */
    done: { count: number; rows: OrderDoneRow[] };
    projects: TaskProject[];
    repositories: { path: string; project: string }[];
    /** A new task starts reportable while the client has an active retainer. */
    reportable_by_default: boolean;
}

export interface OrderDoneRow extends ClientTaskRow {
    /** Y-m-d in the display timezone. */
    done_on: string;
    minutes: number;
}

export interface OrderTimeRow {
    id: number;
    date: string;
    label: string;
    minutes: number;
}

export interface OrderTasks {
    mode: 'order';
    expanded: 'open' | 'done' | 'time' | null;
    open: { count: number; rows: ClientTaskRow[] };
    done: { count: number; rows: OrderDoneRow[] };
    time: { hours: number; count: number; rows: OrderTimeRow[] };
    projects: TaskProject[];
    repositories: { path: string; project: string }[];
    /** A new task starts reportable while the client has an active retainer. */
    reportable_by_default: boolean;
}

export type ClientTasks = RetainerTasks | OrderTasks;
