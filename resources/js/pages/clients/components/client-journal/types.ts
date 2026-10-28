import type { LifecycleStage } from '@/types';

export interface JournalWorkRow {
    key: string;
    kind: 'session' | 'entry';
    date: string;
    title: string | null;
    subtitle: string | null;
    via: string | null;
    minutes: number | null;
    running: boolean;
    writes: number | null;
    is_new: boolean;
}

export interface JournalEntryNote {
    key: string;
    kind: 'note';
    id: number;
    date: string;
    via: string | null;
    author: string | null;
    from_stage: LifecycleStage | null;
    to_stage: LifecycleStage;
    stage_change: boolean;
    note: string | null;
}

/** A close or reopen, rendered from its immutable settlement event: read-only, never an editable note. */
export interface JournalSettlement {
    key: string;
    kind: 'settlement';
    date: string;
    via: null;
    author: string | null;
    settlement: {
        action: 'close' | 'reopen';
        period: string;
        used: number | null;
        contracted: number | null;
        closing: number | null;
        source: 'report' | 'entries' | null;
    };
}

/** A change of one of the account's client fields, read-only. */
export interface JournalFieldChange {
    key: string;
    kind: 'field';
    date: string;
    time: string;
    via: null;
    author: string | null;
    label: string;
    old: string | null;
    new: string | null;
    /** The field's key, `assignee` for the built-in one. */
    field_key?: string;
}

export type JournalNote = JournalEntryNote | JournalSettlement | JournalFieldChange;

export interface JournalProject {
    id: number;
    name: string;
    minutes: number;
    trello_url: string | null;
}

/** One cursor page; "Starsze" asks for the page after `next` and the client appends its rows. */
export interface JournalPage<T> {
    rows: T[];
    next: string | null;
}

export interface ClientJournal {
    since: string;
    since_today: boolean;
    summary: { rows: number; notes: number; person_minutes: number; field_changes?: number; sms?: number };
    stage: { stage: LifecycleStage; since: string | null; retainer_from: string | null };
    period: string;
    projects: JournalProject[];
}
