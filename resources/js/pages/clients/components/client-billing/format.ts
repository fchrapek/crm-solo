import type { ChipStatus } from '@/components/solo/chip';
import type { PlaceLinkPill, PlacePill } from '@/components/solo/shell/place';
import { capitalize, monthName, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import type { TFunction } from 'i18next';
import { hoursNumber, hoursTerms } from '../client-list/format';
import type { ActivePosition, BilledMonth, BillingInvoice, BillingMonth, LedgerRow } from './types';

const h = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

/** Billing on one month: the month travels in the URL, so a reload and a link land on it. */
export function monthUrl(clientId: number, period: string): string {
    return clients.show({ client: clientId, tab: 'billing' }, { query: { month: period } }).url;
}

/** "Wrzesień", with the year once it is not the running one. */
export function monthLabel(period: string, current: string, locale: string): string {
    const name = capitalize(monthName(period, locale));

    return period.slice(0, 4) === current.slice(0, 4) ? name : `${name} ${period.slice(0, 4)}`;
}

export const MONTH_PILLS = 3;

/** The newest months as pills and the older ones in the Older menu by year, whose pill takes the name of an older month shown. */
export function monthPills(months: string[], shown: string, current: string, clientId: number, locale: string, t: TFunction): PlacePill[] {
    const periods = [...new Set([current, ...months, shown])].sort().reverse();
    const pill = (period: string): PlaceLinkPill => ({
        label: monthLabel(period, current, locale),
        href: monthUrl(clientId, period),
        active: period === shown,
        ...(period === current ? { note: t('month_running') } : {}),
    });
    const older = periods.slice(MONTH_PILLS);
    const picked = older.find((period) => period === shown);

    return [
        ...periods.slice(0, MONTH_PILLS).map(pill),
        ...(older.length > 0
            ? [
                  {
                      label: picked !== undefined ? monthLabel(picked, current, locale) : t('Older'),
                      active: picked !== undefined,
                      heading: t('Older months'),
                      // under its year heading a month needs no year of its own
                      menu: older.map((period) => ({ ...pill(period), label: capitalize(monthName(period, locale)), group: period.slice(0, 4) })),
                  },
              ]
            : []),
    ];
}

/** What the month's report is, as the chip and the line under the title name it. */
export type ReportState = 'running' | 'to_generate' | 'stale' | 'draft' | 'finalized' | 'sent';

export function reportState(month: Pick<BillingMonth, 'report'>, running: boolean): ReportState {
    const report = month.report;
    if (report === null) {
        return running ? 'running' : 'to_generate';
    }
    if (report.status === 'draft') {
        return report.needs_recalculation ? 'stale' : 'draft';
    }

    return report.status;
}

export function reportChip(
    month: Pick<BillingMonth, 'report'>,
    running: boolean,
    locale: string,
    t: TFunction,
): { status: ChipStatus; label: string } {
    const report = month.report;
    switch (reportState(month, running)) {
        case 'running':
            return { status: 'open', label: t('report_chip_running') };
        case 'to_generate':
            return { status: 'waiting', label: t('report_chip_to_generate') };
        case 'stale':
            return { status: 'waiting', label: t('report_chip_stale') };
        case 'draft':
            return {
                status: 'open',
                label: report?.generated_on ? t('report_chip_draft', { date: shortDate(report.generated_on, locale) }) : t('ledger_report_draft'),
            };
        case 'finalized':
            return { status: 'waiting', label: t('report_chip_finalized') };
        case 'sent':
            return {
                status: 'done',
                label: report?.sent_on ? t('ledger_report_sent', { date: shortDate(report.sent_on, locale) }) : t('ledger_report_sent_undated'),
            };
    }
}

/** The line under the month's name: when it ends or ended, when it was closed, then its report; the chip beside it carries open or closed. */
export function monthNote(month: BillingMonth, current: string, locale: string, t: TFunction): string {
    const figures = month.figures;
    const running = month.period === current;
    const [year, mon] = month.period.split('-').map(Number);
    const lastDay = `${month.period}-${String(new Date(year, mon, 0).getDate()).padStart(2, '0')}`;
    const state = reportState(month, running);
    const report =
        state === 'running'
            ? figures.has_retainer
                ? t('report_note_after_close')
                : null
            : state === 'to_generate'
              ? figures.has_retainer
                  ? t('report_note_to_generate')
                  : null
              : state === 'stale'
                ? t('report_note_stale')
                : state === 'draft'
                  ? t('ledger_report_draft_named')
                  : state === 'finalized'
                    ? t('ledger_report_finalized_named')
                    : month.report?.sent_on
                      ? t('ledger_report_sent_named', { date: shortDate(month.report.sent_on, locale) })
                      : t('ledger_report_sent_undated_named');
    const end = t(running ? 'month_note_until' : 'month_note_ended', { date: shortDate(lastDay, locale) });
    const closed = figures.closed
        ? figures.closed_on !== null
            ? t('ledger_closed', { date: shortDate(figures.closed_on, locale) })
            : t('ledger_closed_undated')
        : null;

    return [end, closed, report].filter(Boolean).join(' · ');
}

/** The caption under the billed figure: equal to the real time, or how far the owner's figures moved it. */
export function billedCaption(figures: Pick<BilledMonth, 'real' | 'billed' | 'used'>, locale: string, t: TFunction): string {
    const diff = Math.round(((figures.billed ?? figures.used) - figures.real) * 100) / 100;
    if (Math.abs(diff) < 0.005) {
        return t('billed_caption_equal');
    }

    return t(diff < 0 ? 'billed_caption_less' : 'billed_caption_more', { hours: hoursNumber(Math.abs(diff), locale) });
}

/** Hours the rollover cap cut from what the month could draw on. */
export function forfeitedOf(figures: Pick<BilledMonth, 'opening' | 'contracted' | 'available'>): number {
    return Math.max(0, Math.round((figures.opening + (figures.contracted ?? 0) - figures.available) * 100) / 100);
}

/** Settlement first, then the report's delivery; a report beside a settlement names itself. */
export function ledgerStates(row: LedgerRow, locale: string, t: TFunction): { settlement: string | null; report: string | null } {
    const settlement =
        row.settlement === 'closed'
            ? row.closed_on !== null
                ? t('ledger_closed', { date: shortDate(row.closed_on, locale) })
                : t('ledger_closed_undated')
            : row.settlement === 'open'
              ? row.report_id === null
                  ? t('ledger_open_from', { balance: h(row.opening, locale, true) })
                  : t('ledger_open')
              : null;
    const named = row.settlement !== null;
    const report =
        row.report_id === null || row.status === null
            ? null
            : row.status === 'draft'
              ? t(named ? 'ledger_report_draft_named' : 'ledger_report_draft')
              : row.status === 'sent'
                ? row.sent_on !== null
                    ? t(named ? 'ledger_report_sent_named' : 'ledger_report_sent', { date: shortDate(row.sent_on, locale) })
                    : t(named ? 'ledger_report_sent_undated_named' : 'ledger_report_sent_undated')
                : t(named ? 'ledger_report_finalized_named' : 'ledger_report_finalized');

    return { settlement, report };
}

/** The chip of an invoice: the dot carries the state, never vermilion (an overdue invoice waits, it is not over a limit). */
export function invoiceChip(invoice: BillingInvoice, locale: string, t: TFunction): { status: ChipStatus; label: string } {
    const label = invoiceState(invoice, locale, t);
    if (invoice.status === 'paid') {
        return { status: 'done', label };
    }

    return { status: invoice.status === 'draft' ? 'open' : 'waiting', label };
}

/** Grouped from the thousands ("1 800 zł"), which Polish leaves ungrouped by default. */
export function moneyOf(amount: number, currency: string, locale: string, cents: boolean): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        useGrouping: 'always',
        minimumFractionDigits: cents ? 2 : 0,
        maximumFractionDigits: 2,
    }).format(amount);
}

/** "Wrzesień 2026" on the first row and at a year change, the month alone below it; a partial period reads as its dates. */
export function periodLabel(row: LedgerRow, previous: LedgerRow | undefined, locale: string): string {
    if (!row.whole_month) {
        return `${shortDate(row.period_start, locale)}\u2013${shortDate(row.period_end, locale)}`;
    }

    const name = capitalize(monthName(row.period, locale));
    const year = row.period.slice(0, 4);

    return previous === undefined || previous.period.slice(0, 4) !== year ? `${name} ${year}` : name;
}

/** The month an invoice bills: its sale date, else the day it was issued. */
export function invoiceMonth(saleDate: string | null, invoiceDate: string | null, locale: string): string | null {
    const date = saleDate ?? invoiceDate;

    return date !== null ? monthName(date.slice(0, 7), locale) : null;
}

/** "15/09/2026 · sierpień": the number (or the issue date) and the month it bills. */
export function invoiceTitle(invoice: BillingInvoice, locale: string, t: TFunction): string {
    const month = invoiceMonth(invoice.sale_date, invoice.invoice_date, locale);
    const number = invoice.number ?? (invoice.invoice_date !== null ? shortDate(invoice.invoice_date, locale) : t('No number'));

    return month !== null ? `${number} · ${month}` : number;
}

export function invoiceState(invoice: BillingInvoice, locale: string, t: TFunction): string {
    if (invoice.status === 'paid') {
        return invoice.paid_date !== null ? t('invoice_line_paid', { date: shortDate(invoice.paid_date, locale) }) : t('invoice_line_paid_undated');
    }
    if (invoice.overdue && invoice.payment_date !== null) {
        return t('invoice_line_overdue', { date: shortDate(invoice.payment_date, locale) });
    }
    if (invoice.status === 'draft') {
        return t('invoice_line_draft');
    }

    return invoice.payment_date !== null ? t('invoice_line_due', { date: shortDate(invoice.payment_date, locale) }) : t('invoice_line_open');
}

/** An order's invoice on phones (Figma 335:3956): what still waits for money reads in accent, a paid or draft one does not. */
export function orderInvoiceLine(invoice: BillingInvoice, locale: string, t: TFunction): { text: string; accent: boolean } {
    if (invoice.status === 'paid' || invoice.status === 'draft') {
        return { text: invoice.status === 'draft' ? t('invoice_line_draft_short') : invoiceState(invoice, locale, t), accent: false };
    }
    if (invoice.overdue) {
        return { text: invoiceState(invoice, locale, t), accent: true };
    }

    return {
        text:
            invoice.payment_date !== null
                ? t('invoice_line_awaiting', { date: shortDate(invoice.payment_date, locale) })
                : t('invoice_line_awaiting_undated'),
        accent: true,
    };
}

export function positionSummary(position: ActivePosition, several: boolean, locale: string, t: TFunction): string {
    if (position.monthly_hours <= 0) {
        return position.label ?? t('No hours in the package');
    }

    const hours = hoursTerms(position.monthly_hours, locale);
    const summary =
        position.rollover_cap_hours !== null
            ? t('{{hours}} h in the package, rollover up to {{cap}} h', { hours, cap: hoursTerms(position.rollover_cap_hours, locale) })
            : t('{{hours}} h in the package', { hours });

    return several && position.label ? `${position.label}: ${summary}` : summary;
}
