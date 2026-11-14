import type { ClientCurrency } from '@/types';
import type { ClientOrder } from '../client-overview/types';
import type { Retainer } from '../retainer-chip';

export type ReportStatus = 'draft' | 'finalized' | 'sent';

/** One task's month in both values; stated is the owner's figure, null when the task bills its real time. */
export interface BilledTaskFigures {
    task_id: number;
    real: number;
    stated: number | null;
    billed: number;
}

/** A month in both values, in hours: real as logged, billed as charged (the sum of its tasks, or the figure a report or closure settled). */
export interface BilledFigures {
    real: number;
    billed: number;
    tasks: BilledTaskFigures[];
    note: string | null;
}

/** A task row of the billed time dialog, the most billed first. */
export interface BilledTaskRow extends BilledTaskFigures {
    task: string;
    project: string | null;
    note: string | null;
}

/** What the billed time dialog reads (GET client-billed.show). */
export interface BilledMonth {
    client_id: number;
    client: string;
    period: string;
    real: number;
    billed: number | null;
    tasks: BilledTaskRow[];
    note: string | null;
    used: number;
    source: 'closure' | 'report' | 'billed' | 'entries';
    has_retainer: boolean;
    opening: number;
    available: number;
    closing: number;
    contracted: number | null;
    closed: boolean;
    closed_on: string | null;
    closure_id: number | null;
    report: { id: number; status: ReportStatus; sent_on: string | null } | null;
    refusal: string | null;
    refusal_code: 'invalid_argument' | 'period_settled' | 'not_a_draft' | 'refused' | 'not_reportable' | null;
}

/** Settlement ("zamknięty" only from a real closure) and report delivery are separate facts on one row. */
export interface LedgerRow {
    key: string;
    report_id: number | null;
    period: string;
    period_start: string;
    period_end: string;
    whole_month: boolean;
    ended: boolean;
    contracted: number | null;
    used: number;
    available: number;
    opening: number;
    forfeited: number;
    closing: number;
    change: number;
    status: ReportStatus | null;
    sent_on: string | null;
    settlement: 'closed' | 'open' | null;
    active: boolean;
    before_baseline: boolean;
    closed_on: string | null;
    /** Set on the latest closed row only: the one closure that can be reopened. */
    closure_id: number | null;
    reopen_refusal: string | null;
    reopen_blocker: { report_id: number; period: string } | null;
    /** Null for a partial period and for a closure made before billed time existed. */
    billed: BilledFigures | null;
    /** Whether the row offers the pencil; the dialog still reads the full rules. */
    billed_editable: boolean;
}

export interface BillingInvoice {
    id: number;
    number: string | null;
    status: string | null;
    currency: string;
    net: number;
    gross: number;
    invoice_date: string | null;
    sale_date: string | null;
    payment_date: string | null;
    paid_date: string | null;
    overdue: boolean;
    external_url: string | null;
}

/** One invoice group of the billed month on Rozliczenia (InfaktDrafting::monthState); amounts in grosze. */
export interface InvoiceDraftGroup {
    group: number;
    projects: string[];
    state: 'drafted' | 'processing' | 'unconfirmed' | 'blocked' | 'ready' | 'needs_source';
    net: number | null;
    currency: string;
    chained: boolean;
    /** Why a group needs a pick: the last copy attempt's refusal, or what the mirror shows of its source. */
    reason: 'gone' | 'not_vat' | 'draft' | 'advance' | 'settles_advance' | 'unusable' | 'projects_changed' | null;
    source: { number: string | null; sale_date: string | null } | null;
    draft_url: string | null;
}

export interface InvoiceDrafts {
    period: string;
    groups: InvoiceDraftGroup[];
}

/** An invoice the picker offers; amounts in grosze. */
export interface InvoiceSource {
    uuid: string | null;
    number: string | null;
    invoice_date: string | null;
    sale_date: string | null;
    net_price: number;
    currency: string;
    lines: string[];
}

export interface ActivePosition {
    id: number;
    label: string | null;
    monthly_hours: number;
    monthly_fee: number | null;
    rollover_cap_hours: number | null;
    currency: ClientCurrency;
}

export interface RetainerBilling {
    mode: 'retainer';
    cap: number | null;
    settlement: { active_period: string; current_period: string; open_behind: boolean };
    opening_balance: { report_id: number; value: number | null; version: string } | null;
    waiting_report: { report_id: number; period: string; status: ReportStatus } | null;
    retainer: {
        active: ActivePosition[];
        positions: Retainer[];
        active_ids: number[];
        currency: ClientCurrency;
    };
    invoices: { rows: BillingInvoice[]; more: number };
    invoice_drafts: InvoiceDrafts | null;
}

/** A month of a client without a retainer, in both values (no ledger to carry it). */
export interface BilledMonthRow {
    period: string;
    billed: BilledFigures;
    billed_editable: boolean;
}

export interface OrderBilling {
    mode: 'order';
    billed_months: BilledMonthRow[];
    invoices: { rows: BillingInvoice[]; more: number };
    order: ClientOrder;
    reports: OrderReportRow[];
    /** The month a new report is offered for (YYYY-MM): the one just ended. */
    report_period: string;
    composers: { key: string; label: string }[];
    currency: ClientCurrency;
}

export interface OrderReportRow {
    id: number;
    period: string;
    status: 'draft' | 'finalized' | 'sent';
}

export type ClientBilling = RetainerBilling | OrderBilling;

/** One page of the hours bank; "Starsze" reloads only this prop with `next` and the rows are appended. */
export interface BillingLedger {
    rows: LedgerRow[];
    next: string | null;
    start: { date: string; balance: number } | null;
}

/** The month Rozliczenia shows (billing_month): what the billed time dialog reads, plus the month's report, invoices and checklist. */
export interface BillingMonth {
    period: string;
    figures: BilledMonth;
    report: {
        id: number;
        status: ReportStatus;
        sent_on: string | null;
        generated_on: string | null;
        needs_recalculation: boolean;
        composer_key: string | null;
    } | null;
    /** Invoices booked in the month (sale date first), from the Infakt mirror. */
    invoices: BillingInvoice[];
    checklist: { done: number; total: number } | null;
    composers: { key: string; label: string }[];
}

export interface ClientRevenueSummary {
    currency: string;
    period_net: number;
    period_gross: number;
    period_invoice_count: number;
    alltime_net: number;
    alltime_gross: number;
    share_net: number;
    last_invoice_date: string | null;
}

/** The client's whole invoice list for a year, a month or a rolling year (billing_invoices), with the revenue of that window. */
export interface BillingInvoiceList {
    rows: BillingInvoice[];
    total: number;
    truncated: boolean;
    filters: { year: number | 'all' | 'last12'; month: number | null; years: number[] };
    revenue: ClientRevenueSummary | null;
}
