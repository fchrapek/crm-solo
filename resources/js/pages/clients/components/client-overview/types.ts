import type { ClientCard, MessageRow } from '../client-fields';
import type { ProjectDialogValues } from '../project-form-dialog/project-form-dialog';

export interface OverviewHours {
    period: string;
    has_retainer: boolean;
    contracted: number | null;
    carried: number;
    cap: number | null;
    available: number;
    used: number;
    remaining: number;
    logged: number;
    forfeited: number;
    report_id: number | null;
    report_status: string | null;
}

export interface ClientOverview {
    /** The account's fields marked for Przegląd, with the assignee; null when the account has no fields. */
    card: ClientCard | null;
    /** The client's failed, scheduled and sent-today text messages, latest first, null alongside card. */
    sms: MessageRow[] | null;
    /** Every scheduled one; sms lists five. */
    sms_pending?: number;
    /** Every failed one, until it is sent again or cancelled. */
    sms_failed?: number;
    /** A message still waits or is being sent, so Przegląd polls for its outcome. */
    sms_live?: boolean;
    report_due: { period: string; report_id: number | null; status: 'draft' | 'finalized' | null } | null;
    month_close: { period: string; started: boolean; done: number; total: number } | null;
    doing_count: number;
    journal: { since: string; since_today: boolean; count: number };
    /** Null in the order layout, which has no hours bank. */
    hours: OverviewHours | null;
    order: ClientOrder | null;
    order_waiting: { task: OrderWaitingTask | null; invoice: OrderWaitingInvoice | null } | null;
    settlement: OverviewSettlement | null;
}

export interface OrderWaitingTask {
    task_id: number;
    name: string;
    due: string;
    /** The owner's running timer on it, the one Stop closes. */
    running_id: number | null;
}

export interface OrderWaitingInvoice {
    id: number;
    number: string | null;
    payment_date: string | null;
    overdue: boolean;
    external_url: string | null;
}

export interface OrderInvoice {
    id: number;
    number: string | null;
    state: 'draft' | 'unpaid' | 'paid';
    currency: string;
    gross: number;
    net: number;
    invoice_date: string | null;
    payment_date: string | null;
    paid_date: string | null;
    overdue: boolean;
}

export interface ClientOrder {
    title: string;
    /** The catch-all project the order reads, with every field its editor sends back. */
    project: ProjectDialogValues | null;
    started_on: string | null;
    hours: number;
    month_hours: number;
    tasks: { done: number; total: number };
    state: 'in_progress' | 'done' | null;
    invoice: OrderInvoice | null;
}

export interface OverviewSettlement {
    active_period: string;
    current_period: string;
    open_behind: boolean;
}
