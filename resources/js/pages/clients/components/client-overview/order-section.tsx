import { HoursBar } from '@/components/solo/hours-bar';
import { KvRow } from '@/components/solo/kv-row';
import { Stat } from '@/components/solo/primitives';
import { Tile, TileHeader, TileNote } from '@/components/solo/tile';
import { shortDate } from '@/lib/dates';
import { hoursText } from '@/lib/hours-text';
import { useTranslation } from 'react-i18next';
import { moneyOf } from '../client-billing/format';
import styles from './client-overview.module.css';
import { orderInvoiceNumber, orderInvoiceState, orderStateLabel, orderTasksLine } from './order-format';
import type { ClientOrder, OrderInvoice } from './types';

/** The Zlecenie tile in place of Godziny: the order, its time and tasks, then the invoice as the figure. */
export function OrderSection({ order, locale }: { order: ClientOrder; locale: string }) {
    const { t } = useTranslation();
    const state = orderStateLabel(order.state, t);
    const invoice = order.invoice;

    return (
        <Tile kind="plain">
            <TileHeader label={t('Order')} end={state ?? undefined} />
            <dl className={styles.ledger}>
                <KvRow layout="tile" label={order.title}>
                    {order.started_on !== null ? t('since {{date}}', { date: shortDate(order.started_on, locale) }) : ''}
                </KvRow>
                {invoice !== null && (
                    <KvRow layout="tile" label={t('Amount')}>
                        {t('{{money}} gross', { money: moneyOf(invoice.gross, invoice.currency, locale, false) })}
                    </KvRow>
                )}
                <KvRow layout="tile" label={t('Time')}>
                    {`${hoursText(order.hours, locale)} h`}
                </KvRow>
            </dl>
            {order.tasks.total > 0 && (
                <>
                    <div data-fill="">
                        <HoursBar used={order.tasks.done} available={order.tasks.total} size="tile" />
                    </div>
                    <TileNote>{orderTasksLine(order, t)}</TileNote>
                </>
            )}
            {invoice !== null ? (
                <>
                    <Stat
                        kind="tile"
                        label={`${t('Invoice {{number}}', { number: orderInvoiceNumber(invoice, locale, t) })} · ${orderInvoiceState(invoice, t)}`}
                        value={moneyOf(invoice.gross, invoice.currency, locale, false)}
                    />
                    <InvoiceDates invoice={invoice} locale={locale} />
                </>
            ) : (
                <TileNote>{t('order_no_invoice')}</TileNote>
            )}
        </Tile>
    );
}

function InvoiceDates({ invoice, locale }: { invoice: OrderInvoice; locale: string }) {
    const { t } = useTranslation();
    const parts: string[] = [];
    if (invoice.invoice_date !== null) {
        parts.push(t('Issued {{date}}', { date: shortDate(invoice.invoice_date, locale) }));
    }
    if (invoice.state === 'paid' && invoice.paid_date !== null) {
        parts.push(t('paid {{date}}', { date: shortDate(invoice.paid_date, locale) }));
    }
    const overdue = invoice.state === 'unpaid' && invoice.overdue && invoice.payment_date !== null;
    if (invoice.state === 'unpaid' && !overdue && invoice.payment_date !== null) {
        parts.push(t('payment due {{date}}', { date: shortDate(invoice.payment_date, locale) }));
    }
    if (parts.length === 0 && !overdue) {
        return null;
    }

    return (
        <TileNote className={styles.dates}>
            {parts.join(' · ')}
            {overdue && invoice.payment_date !== null && (
                <>
                    {parts.length > 0 && ' · '}
                    <span data-tone="over">{t('overdue since {{date}}', { date: shortDate(invoice.payment_date, locale) })}</span>
                </>
            )}
        </TileNote>
    );
}
