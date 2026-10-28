import type { ClientCurrency } from '@/types';
import type { ClientOrder } from '../client-overview/types';
import type { Retainer } from '../retainer-chip';

export type ReportStatus = 'draft' | 'finalized' | 'sent';

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
}

export interface OrderBilling {
    mode: 'order';
    invoices: { rows: BillingInvoice[]; more: number };
    order: ClientOrder;
}

export type ClientBilling = RetainerBilling | OrderBilling;

/** One page of the hours bank; "Starsze" reloads only this prop with `next` and the rows are appended. */
export interface BillingLedger {
    rows: LedgerRow[];
    next: string | null;
    start: { date: string; balance: number } | null;
}
