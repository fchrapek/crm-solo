import { capitalize, monthName } from '@/lib/dates';
import monthClose from '@/routes/month-close';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';

export type StepState = 'pending' | 'done' | 'skipped';

export interface Step {
    key: string;
    project_id: number | null;
    project: string | null;
    state: StepState;
    owner: 'you' | 'agent';
    note: string | null;
    completed_on: string | null;
    via: 'agent' | 'web' | null;
}

/** The financial close of the month, beside the checklist: never a step, never tickable. */
export interface Settlement {
    state: 'ready' | 'closed' | 'blocked';
    period: string;
    active_period: string;
    closed_on: string | null;
    closing: number | null;
}

// English strings double as the i18n keys.
const STEP_LABELS: Record<string, string> = {
    db_archived: 'DB archived to vault',
    local_db_import: 'Local DB imported',
    wp_updates: 'WordPress updates',
    local_verify: 'Local verify',
    commit_merge: 'Committed and merged',
    live_deploy: 'Pushed and deployed live',
    reconcile_log: 'Session log reconciled',
    report: 'Report generated',
    summary_email: 'Summary email sent',
    draft_invoice: 'Draft invoice issued',
    // Retired keys, so runs closed before the per-site split still read.
    live_check: 'Live page checked',
    db_dump: 'DB dump archived',
    full_site_copy: 'Full site copy',
};

export function stepLabel(key: string, t: TFunction): string {
    return STEP_LABELS[key] === undefined ? key : t(STEP_LABELS[key]);
}

/** "Wrzesień": the month as a label that starts a line. */
export function monthLabel(period: string, locale: string): string {
    return capitalize(monthName(period, locale));
}

/** A move keeps the page's state, so the card stays where it was scrolled. */
export function moveStep(clientId: number, period: string, step: Step, state: StepState): void {
    router.patch(
        monthClose.tick(clientId).url,
        { period, step: step.key, project_id: step.project_id, state },
        { preserveScroll: true, preserveState: true },
    );
}
