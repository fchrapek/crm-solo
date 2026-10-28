import { HoursBar } from '@/components/solo/hours-bar';
import { KvRow } from '@/components/solo/kv-row';
import { SectionHead } from '@/components/solo/primitives';
import { splitSideClass } from '@/components/solo/split';
import { shortDate } from '@/lib/dates';
import { hoursText } from '@/lib/hours-text';
import { useTranslation } from 'react-i18next';
import { moneyOf } from '../client-billing/format';
import styles from './client-overview.module.css';
import { orderInvoiceNumber, orderInvoiceState, orderStateLabel, orderTasksLine } from './order-format';
import type { ClientOrder, OrderInvoice } from './types';

/** Figma D8.3: the Zlecenie block in place of Godziny. The deadline caption and the contract date wait for data to hold them. */
export function OrderSection({ order, locale }: { order: ClientOrder; locale: string }) {
    const { t } = useTranslation();
    const state = orderStateLabel(order.state, t);
    const invoice = order.invoice;

    return (
        <section className={`${splitSideClass} ${styles.hours}`}>
            <SectionHead kind="body" phone="plain" label={t('Order')} meta={state ?? undefined} />
            <dl className={styles.ledger}>
                <KvRow layout="ledger" label={order.title}>
                    {order.started_on !== null ? t('since {{date}}', { date: shortDate(order.started_on, locale) }) : ''}
                </KvRow>
                {invoice !== null && (
                    <KvRow layout="ledger" label={t('Amount')}>
                        {t('{{money}} gross', { money: moneyOf(invoice.gross, invoice.currency, locale, false) })}
                    </KvRow>
                )}
                <KvRow layout="ledger" label={t('Time')}>
                    {`${hoursText(order.hours, locale)} h`}
                </KvRow>
            </dl>
            {order.tasks.total > 0 && (
                <div className={styles.bar}>
                    <HoursBar used={order.tasks.done} available={order.tasks.total} size="block" />
                    <p className={styles.caption}>
                        <span>{orderTasksLine(order, t)}</span>
                    </p>
                </div>
            )}
            <div className={[styles.balance, styles.orderPoster].filter(Boolean).join(' ')}>
                {invoice !== null ? (
                    <>
                        <p className={styles.balanceValue}>{moneyOf(invoice.gross, invoice.currency, locale, false)}</p>
                        <p className={styles.balanceLabel}>
                            {`${t('Invoice {{number}}', { number: orderInvoiceNumber(invoice, locale, t) })} · ${orderInvoiceState(invoice, t)}`}
                        </p>
                        <InvoiceDates invoice={invoice} locale={locale} />
                    </>
                ) : (
                    <p className={styles.balanceLabel}>{t('order_no_invoice')}</p>
                )}
            </div>
        </section>
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
        <p className={styles.posterNote}>
            {parts.join(' · ')}
            {overdue && invoice.payment_date !== null && (
                <>
                    {parts.length > 0 && ' · '}
                    <span data-tone="over">{t('overdue since {{date}}', { date: shortDate(invoice.payment_date, locale) })}</span>
                </>
            )}
        </p>
    );
}
