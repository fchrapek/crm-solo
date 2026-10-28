import { capitalize, monthName, shortDate } from '@/lib/dates';
import type { TFunction } from 'i18next';
import { hoursTerms } from '../client-list/format';
import type { ActivePosition, BillingInvoice, LedgerRow } from './types';

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
