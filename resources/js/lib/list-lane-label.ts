import type { TFunction } from 'i18next';

/** The five manual lanes every project has; any other lane is custom or from Trello. */
export const CANONICAL_LANES = ['Backlog', 'To-Do', 'Doing', 'Testing', 'Done'] as const;

export type CanonicalLane = (typeof CANONICAL_LANES)[number];

export const isCanonicalLane = (lane: string): lane is CanonicalLane => (CANONICAL_LANES as readonly string[]).includes(lane);

/**
 * A task's list (lane) name for display: the five manual lanes translate
 * through `list_lane_*`, any other lane keeps its own name. Only canonical
 * names reach t(), because i18next reads a colon as a namespace separator
 * and would cut "QA:Blocked" down to "Blocked".
 */
export function listLaneLabel(t: TFunction, laneName: string | null | undefined): string {
    if (!laneName) return '-';

    return isCanonicalLane(laneName) ? t(`list_lane_${laneName}`) : laneName;
}
