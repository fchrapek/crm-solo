export type CloseSource = 'report' | 'entries' | 'billed';

/** Both values of a month, in hours: real as logged, billed as charged (the sum of its task figures). */
export interface BilledSnapshot {
    real: number;
    billed: number;
}

/** What closing a month would freeze, read when the dialog opens (GET client-months.preview). */
export interface ClosePreview {
    client: string;
    period: string;
    next: string;
    contracted: number | null;
    opening: number;
    cap: number | null;
    forfeited: number;
    used: number;
    closing: number;
    source: CloseSource;
    report_status: 'draft' | 'finalized' | 'sent' | null;
    entries: number;
    /** Absent from a closure made before billed time existed. */
    billed_time?: BilledSnapshot;
    hash: string;
    refusal: string | null;
}

/** A closed month the owner can reopen: only the latest closure, with what the server already knows would refuse it. */
export interface ReopenTarget {
    period: string;
    closure_id: number;
    used: number;
    closing: number;
    refusal: string | null;
    /** The later report the refusal names; the phone's blocked sheet links to it. */
    blocker: { report_id: number; period: string } | null;
}
