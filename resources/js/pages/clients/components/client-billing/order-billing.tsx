import { KvRow } from '@/components/solo/kv-row';
import { SectionAction, SectionHead } from '@/components/solo/primitives';
import { splitMainClass } from '@/components/solo/split';
import { shortDate } from '@/lib/dates';
import { hoursText } from '@/lib/hours-text';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { orderInvoiceNumber, orderStateLabel, orderTasksLine } from '../client-overview/order-format';
import type { ClientOrder } from '../client-overview/types';
import { ProjectFormDialog } from '../project-form-dialog';
import styles from './client-billing.module.css';
import { moneyOf } from './format';

type Translate = ReturnType<typeof useTranslation>['t'];

/** Figma D8.6 brief. */
export function OrderBrief({ order, locale }: { order: ClientOrder; locale: string }) {
    const { t } = useTranslation();
    const invoice = order.invoice;
    const state = order.state === null ? null : order.state === 'in_progress' ? t('order_brief_state_in_progress') : t('order_brief_state_done');

    return (
        <p className={styles.brief}>
            {invoice !== null
                ? t('order_brief_amount', { title: order.title, money: moneyOf(invoice.gross, invoice.currency, locale, false) })
                : `${order.title}.`}{' '}
            {invoiceClause(order, locale, t)}
            {state !== null && `, ${state}`}.
        </p>
    );
}

function invoiceClause(order: ClientOrder, locale: string, t: Translate): ReactNode {
    const invoice = order.invoice;
    if (invoice === null) {
        return t('order_brief_no_invoice');
    }

    const number = orderInvoiceNumber(invoice, locale, t);
    if (invoice.state === 'paid') {
        return invoice.paid_date !== null
            ? t('order_brief_paid', { number, date: shortDate(invoice.paid_date, locale) })
            : t('order_brief_paid_undated', { number });
    }
    if (invoice.state === 'draft') {
        return t('order_brief_draft', { number });
    }

    const lead = t('Invoice {{number}}', { number });
    if (invoice.overdue && invoice.payment_date !== null) {
        return (
            <>
                {lead} <span className={styles.accent}>{t('overdue since {{date}}', { date: shortDate(invoice.payment_date, locale) })}</span>
            </>
        );
    }

    return (
        <>
            {lead} <span className={styles.accent}>{t('order_invoice_unpaid')}</span>
            {invoice.payment_date !== null && ` ${t('order_brief_until', { date: shortDate(invoice.payment_date, locale) })}`}
        </>
    );
}

/** Figma D8.6 Zlecenie: the Umowa and Termin rows wait for data to hold them. */
export function OrderSection({ order, locale }: { order: ClientOrder; locale: string }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const project = order.project;
    const invoice = order.invoice;
    const state = orderStateLabel(order.state, t);
    const hours = hoursText(order.hours, locale);
    const tasks = orderTasksLine(order, t);

    return (
        <section className={`${splitMainClass} ${styles.main}`}>
            <SectionHead
                kind="body"
                label={t('Order')}
                action={project !== null ? <SectionAction onClick={() => setEditing(true)}>{t('Edit')}</SectionAction> : undefined}
            />
            <dl className={styles.list}>
                <KvRow label={t('Project')}>{order.title}</KvRow>
                {project?.description && <KvRow label={t('Scope')}>{project.description}</KvRow>}
                {invoice !== null && (
                    <KvRow label={t('Amount')}>
                        {t('{{gross}} gross ({{net}} net)', {
                            gross: moneyOf(invoice.gross, invoice.currency, locale, true),
                            net: moneyOf(invoice.net, invoice.currency, locale, true),
                        })}
                    </KvRow>
                )}
                <KvRow label={t('Time')}>
                    {order.month_hours > 0 && order.month_hours < order.hours
                        ? t('{{hours}} h, {{month}} h of it this month', { hours, month: hoursText(order.month_hours, locale) })
                        : `${hours} h`}
                </KvRow>
                <KvRow label={t('Status')}>{state !== null ? `${state}, ${tasks}` : tasks}</KvRow>
            </dl>
            <p className={styles.footnote}>{t('order_footnote')}</p>
            {project !== null && <ProjectFormDialog open={editing} onOpenChange={setEditing} initial={project} />}
        </section>
    );
}
