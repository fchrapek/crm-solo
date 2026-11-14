import { capitalize, monthName, shortDate } from '@/lib/dates';
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

/** The month's summary email as a step row tells it; dates are local days. */
export interface SummaryEmailState {
    status: 'draft' | 'queued' | 'sent' | 'test_sent' | 'failed' | 'unconfirmed' | null;
    drafted_on: string | null;
    drafted_via: 'agent' | 'web' | null;
    sent_on: string | null;
    changed_on: string | null;
    recipients: number;
}

/** Figma: step states (992:32501): the meta (long on desktop, short on phones), its colour and the row's action. */
export function emailStep(
    state: SummaryEmailState,
    locale: string,
    t: TFunction,
): { meta: string; short: string; tone?: 'warn' | 'error'; action: string } {
    const day = (value: string | null) => (value === null ? '' : shortDate(value, locale));
    switch (state.status) {
        case null:
            return { meta: t('You'), short: t('You'), action: t('Prepare draft') };
        case 'draft': {
            const short = t('draft {{date}}', { date: day(state.drafted_on) });
            return { meta: state.drafted_via === 'agent' ? `${short} · ${t('agent')}` : short, short, action: t('Open draft') };
        }
        case 'test_sent':
            return {
                meta: t('test {{date}}', { date: day(state.changed_on) }),
                short: t('test {{date}}', { date: day(state.changed_on) }),
                tone: 'warn',
                action: t('Open draft'),
            };
        case 'sent':
            return {
                meta: `${t('sent {{date}}', { date: day(state.sent_on) })} · ${t('person_count', { count: state.recipients })}`,
                short: t('sent {{date}}', { date: day(state.sent_on) }),
                action: t('See'),
            };
        case 'failed':
            return { meta: t('not sent {{date}}', { date: day(state.changed_on) }), short: t('not sent'), tone: 'error', action: t('Open') };
        case 'unconfirmed':
            return { meta: t('unconfirmed {{date}}', { date: day(state.changed_on) }), short: t('unconfirmed'), tone: 'warn', action: t('Open') };
        default:
            return { meta: t('Sending…'), short: t('Sending…'), action: t('Open') };
    }
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

/** A skipped draft (a quarterly client) is resolved, as a skipped step is in the step count. */
export function invoiceDrafts(rows: { invoice: StepState | null }[]): { done: number; total: number } {
    const counted = rows.filter((row) => row.invoice !== null);

    return { done: counted.filter((row) => row.invoice !== 'pending').length, total: counted.length };
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
