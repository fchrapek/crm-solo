import type { ClientCooperationType } from '@/types';

export type ListStage = 'active' | 'paused' | 'churned' | 'archived';

/** A cooperation type, or none for the clients without one. */
export type CooperationFilter = ClientCooperationType | 'none';

/** me, none or a user id. */
export type AssigneeFilter = 'me' | 'none' | number;

export interface ListQuery {
    stage: ListStage;
    cooperation_type: CooperationFilter | null;
    search: string;
    /** An account with its own fields: the assignee and choice field filters. */
    assignee?: AssigneeFilter | null;
    f?: Record<string, string>;
}

/** Defaults stay out of the URL. */
export function listQuery({ stage, cooperation_type, search, assignee, f }: ListQuery) {
    return {
        ...(stage !== 'active' ? { stage } : {}),
        ...(cooperation_type ? { cooperation_type } : {}),
        ...(search ? { search } : {}),
        ...(assignee !== null && assignee !== undefined ? { assignee } : {}),
        ...(f !== undefined && Object.keys(f).length > 0 ? { f } : {}),
    };
}

/** Two queries ask for the same page. */
export function sameQuery(a: ListQuery, b: ListQuery): boolean {
    return JSON.stringify(listQuery(a)) === JSON.stringify(listQuery(b));
}

export interface FacetOption<T> {
    value: T | null;
    label: string | null;
    count: number;
}

/** Klienci for an account with its own fields: the overview columns, the assignee and choice field filters, the SMS line. */
export interface ListCard {
    billing: boolean;
    filters: { assignee: AssigneeFilter | null; f: Record<string, string> };
    columns: { key: string; label: string }[];
    assignees: FacetOption<AssigneeFilter>[];
    facets: { key: string; label: string; options: FacetOption<string>[] }[];
    sms: { today: number; failed: number };
}

export type CooperationCounts = Record<'all' | CooperationFilter, number>;

export const STAGE_LABEL: Record<ListStage, string> = {
    active: 'Active clients',
    paused: 'Paused clients',
    churned: 'Former clients',
    archived: 'Archived clients',
};

/** What Wyczyść in the phone filter puts back; the search stays. */
export const CLEAR_FILTERS = { stage: 'active', cooperation_type: null } as const;

/** The Status segments of the phone filter; archived is offered on desktop only. */
export const PHONE_STAGES: ListStage[] = ['active', 'paused', 'churned'];

/** The desktop filter offers every status, Archived included, even at 0. */
export const DESKTOP_STAGES = Object.keys(STAGE_LABEL) as ListStage[];

const COOPERATION_LABEL: Record<CooperationFilter, string> = {
    retainer: 'Retainer',
    hourly: 'Hourly',
    project: 'Project',
    one_off: 'One-off work',
    none: 'No type',
};

const COOPERATION_ORDER: CooperationFilter[] = ['retainer', 'hourly', 'project', 'one_off', 'none'];

export interface CooperationOption {
    value: CooperationFilter | null;
    label: string;
    count: number;
    active: boolean;
}

/** Wszyscy, then every type that has clients or is the current filter; a hidden type counts 0, so Wszyscy is the sum of the rows. */
export function cooperationOptions(counts: CooperationCounts, current: CooperationFilter | null): CooperationOption[] {
    return [
        { value: null, label: 'All clients', count: counts.all, active: current === null },
        ...COOPERATION_ORDER.filter((type) => counts[type] > 0 || current === type).map((type) => ({
            value: type,
            label: COOPERATION_LABEL[type],
            count: counts[type],
            active: current === type,
        })),
    ];
}

export function cooperationLabel(type: CooperationFilter | ClientCooperationType): string {
    return COOPERATION_LABEL[type];
}
