import type { ClientTaskRow, LaneKey } from './types';

/** "~/Sites/site" for a path under a macOS home folder, as the frame and a terminal show it. */
export function homePath(path: string): string {
    return path.replace(/^\/Users\/[^/]+(?=\/|$)/, '~');
}

export function initialLane<T extends { key: string; count: number }>(lanes: T[], expanded: string | null): string {
    return expanded ?? lanes.find((lane) => lane.count > 0)?.key ?? lanes[0]?.key ?? 'doing';
}

export interface TickedRow {
    lane: LaneKey;
    index: number;
    row: ClientTaskRow;
}

/** The tick stopped every timer on the task, the agent's included. */
const retained = (row: ClientTaskRow): ClientTaskRow => ({
    ...row,
    done: true,
    can_untick: true,
    running_id: null,
    running_since: null,
    agent_live: false,
    picked: false,
});

/** A row ticked on this visit stays in its place, ticked and untickable, after the reload drops it from the open list. */
export function withTicked(lane: LaneKey, rows: ClientTaskRow[], ticked: Record<number, TickedRow>): ClientTaskRow[] {
    const merged = rows.map((row) => (ticked[row.task_id] ? retained(row) : row));
    const missing = Object.values(ticked)
        .filter((entry) => entry.lane === lane && !rows.some((row) => row.task_id === entry.row.task_id))
        .sort((a, b) => a.index - b.index);

    for (const entry of missing) {
        merged.splice(Math.min(entry.index, merged.length), 0, retained(entry.row));
    }

    return merged;
}
