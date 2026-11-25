import monthClose from '@/routes/month-close';
import { type Settlement } from './steps';

export interface CloseRow {
    client: { id: number; name: string };
    total: number;
    resolved: number;
    next: { key: string; owner: 'you' | 'agent' } | null;
    sites: number;
    invoice: 'pending' | 'done' | 'skipped' | null;
    settlement: Settlement | null;
    finished_on: string | null;
}

export const FILTERS = ['all', 'you', 'agent', 'hours', 'finished'] as const;

export type CloseFilter = (typeof FILTERS)[number];

/** A row whose checklist is done and whose hours month still waits for its close. */
export const owesHours = (row: CloseRow) => row.next === null && row.settlement !== null && row.settlement.state !== 'closed';

/** Which rows a filter keeps: whose step is next, the hours left to close, or the clients that are through. */
export function matchesFilter(row: CloseRow, filter: CloseFilter): boolean {
    switch (filter) {
        case 'all':
            return true;
        case 'finished':
            return row.finished_on !== null;
        case 'hours':
            return row.finished_on === null && owesHours(row);
        default:
            return row.finished_on === null && row.next?.owner === filter;
    }
}

/** The filter the URL names (`?filter=`); anything else shows everyone. */
export function filterOf(url: string): CloseFilter {
    const named = new URLSearchParams(url.split('?')[1] ?? '').get('filter');

    return FILTERS.find((filter) => filter === named) ?? 'all';
}

export function closeListUrl(period: string, filter: CloseFilter = 'all'): string {
    return monthClose.index({ query: { period, ...(filter !== 'all' ? { filter } : {}) } }).url;
}
