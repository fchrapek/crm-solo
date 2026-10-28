export type LaneKey = 'doing' | 'testing' | 'todo';

export interface ClientTaskRow {
    task_id: number;
    name: string;
    done: boolean;
    can_untick: boolean;
    /** Y-m-d in the display timezone. */
    due: string | null;
    /** The owner's running timer on this task, the one Stop closes. */
    running_id: number | null;
    /** ISO start of that timer, for the elapsed time phones show. */
    running_since: string | null;
    agent_live: boolean;
    /** One of today's picks already. */
    picked: boolean;
}

export interface ClientTaskLane {
    key: LaneKey;
    count: number;
    rows: ClientTaskRow[];
}

export interface RetainerTasks {
    mode: 'retainer';
    /** The lane sent whole; every other lane carries its first five rows. */
    expanded: LaneKey | null;
    lanes: ClientTaskLane[];
    projects: { id: number; name: string }[];
    repositories: { path: string; project: string }[];
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
    projects: { id: number; name: string }[];
    repositories: { path: string; project: string }[];
}

export type ClientTasks = RetainerTasks | OrderTasks;
