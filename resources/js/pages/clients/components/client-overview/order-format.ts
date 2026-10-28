import { shortDate } from '@/lib/dates';
import type { TFunction } from 'i18next';
import type { ClientOrder, OrderInvoice } from './types';

export function orderStateLabel(state: ClientOrder['state'], t: TFunction): string | null {
    if (state === null) {
        return null;
    }

    return state === 'in_progress' ? t('order_state_in_progress') : t('order_state_done');
}

export function orderInvoiceState(invoice: OrderInvoice, t: TFunction): string {
    return invoice.state === 'draft' ? t('order_invoice_draft') : invoice.state === 'paid' ? t('order_invoice_paid') : t('order_invoice_unpaid');
}

export function orderInvoiceNumber(invoice: OrderInvoice, locale: string, t: TFunction): string {
    return invoice.number ?? (invoice.invoice_date !== null ? shortDate(invoice.invoice_date, locale) : t('No number'));
}

export function orderTasksLine(order: ClientOrder, t: TFunction): string {
    return order.tasks.total > 0 ? t('{{done}} of {{count}} tasks done', { done: order.tasks.done, count: order.tasks.total }) : t('No tasks yet.');
}
