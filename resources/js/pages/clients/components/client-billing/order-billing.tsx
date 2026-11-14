import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, Stat } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { Tile, TileActions, TileHeader, TileNote, TileRows } from '@/components/solo/tile';
import { InfoHint } from '@/components/ui/info-hint';
import { capitalize, monthName, shortDate } from '@/lib/dates';
import { hoursText } from '@/lib/hours-text';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { orderInvoiceNumber, orderStateLabel, orderTasksLine } from '../client-overview/order-format';
import type { ClientOrder } from '../client-overview/types';
import clientReports from '@/routes/client-reports';
import { statusLabelKey } from '../../reports/status-actions';
import { GenerateReportDialog, type ComposerOption } from '../generate-report-dialog';
import { ProjectFormDialog } from '../project-form-dialog';
import { RetainerDialog } from '../retainer-chip';
import { billedTip, bothValues } from './billed-format';
import styles from './client-billing.module.css';
import { moneyOf } from './format';
import type { ClientCurrency } from '@/types';
import type { BilledMonthRow, OrderReportRow } from './types';

/** The line under ZLECENIE: the order, where its invoice stands, and its state. */
export function orderNote(order: ClientOrder, locale: string, t: TFunction): string {
    const state = order.state === null ? null : order.state === 'in_progress' ? t('order_brief_state_in_progress') : t('order_brief_state_done');

    return [order.title, orderInvoiceLine(order, locale, t), state].filter(Boolean).join(' · ');
}

function orderInvoiceLine(order: ClientOrder, locale: string, t: TFunction): string {
    const invoice = order.invoice;
    if (invoice === null) {
        return t('order_brief_no_invoice');
    }

    const number = orderInvoiceNumber(invoice, locale, t);
    if (invoice.state === 'paid') {
        return invoice.paid_date !== null ? t('order_brief_paid', { number, date: shortDate(invoice.paid_date, locale) }) : t('order_brief_paid_undated', { number });
    }
    if (invoice.state === 'draft') {
        return t('order_brief_draft', { number });
    }

    const lead = t('Invoice {{number}}', { number });
    if (invoice.overdue && invoice.payment_date !== null) {
        return `${lead} ${t('overdue since {{date}}', { date: shortDate(invoice.payment_date, locale) })}`;
    }

    return [lead, t('order_invoice_unpaid'), invoice.payment_date !== null ? t('order_brief_until', { date: shortDate(invoice.payment_date, locale) }) : null]
        .filter(Boolean)
        .join(' ');
}

/** Figma: Zlecenie, the number tiles: the amount as agreed (gross), the time, and the tasks done. */
export function OrderStats({ order, locale }: { order: ClientOrder; locale: string }) {
    const { t } = useTranslation();
    const invoice = order.invoice;

    return (
        <div className={styles.stats} data-count={invoice !== null ? 3 : 2}>
            {invoice !== null && (
                <Tile kind="plain" as="div">
                    <Stat
                        kind="tile"
                        label={t('Amount')}
                        value={moneyOf(invoice.gross, invoice.currency, locale, false)}
                        sub={t('order_stat_net', { net: moneyOf(invoice.net, invoice.currency, locale, true) })}
                    />
                </Tile>
            )}
            <Tile kind="plain" as="div">
                <Stat
                    kind="tile"
                    label={t('Time')}
                    value={`${hoursText(order.hours, locale)}\u00a0h`}
                    sub={order.month_hours > 0 ? t('order_stat_month', { hours: hoursText(order.month_hours, locale) }) : undefined}
                />
            </Tile>
            <Tile kind="plain" as="div">
                <Stat kind="tile" label={t('Tasks')} value={t('order_stat_tasks', order.tasks)} sub={t('order_stat_done')} />
            </Tile>
        </div>
    );
}

/** Figma: Zlecenie, the order's facts; Edytuj opens the catch-all project the order reads, and a first retainer turns the screen into the retainer's months. */
export function OrderTile({ clientId, order, currency, locale }: { clientId: number; order: ClientOrder; currency: ClientCurrency; locale: string }) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState(false);
    const [retainer, setRetainer] = useState(false);
    const project = order.project;
    const invoice = order.invoice;
    const state = orderStateLabel(order.state, t);
    const hours = hoursText(order.hours, locale);
    const tasks = orderTasksLine(order, t);

    return (
        <Tile kind="plain">
            <TileHeader
                label={t('Order')}
                end={
                    project !== null ? (
                        <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setEditing(true)}>
                            {t('Edit')}
                        </SoloButton>
                    ) : undefined
                }
            />
            <dl className={styles.ledger}>
                <KvRow layout="tile" label={t('Project')}>
                    {order.title}
                </KvRow>
                {project?.description && (
                    <KvRow layout="tile" label={t('Scope')}>
                        {project.description}
                    </KvRow>
                )}
                {invoice !== null && (
                    <KvRow layout="tile" label={t('Amount')}>
                        {t('{{gross}} gross ({{net}} net)', {
                            gross: moneyOf(invoice.gross, invoice.currency, locale, true),
                            net: moneyOf(invoice.net, invoice.currency, locale, true),
                        })}
                    </KvRow>
                )}
                <KvRow layout="tile" label={t('Time')}>
                    {order.month_hours > 0 && order.month_hours < order.hours
                        ? t('{{hours}} h, {{month}} h of it this month', { hours, month: hoursText(order.month_hours, locale) })
                        : `${hours} h`}
                </KvRow>
                <KvRow layout="tile" label={t('Status')}>
                    {state !== null ? `${state}, ${tasks}` : tasks}
                </KvRow>
            </dl>
            <TileNote>{t('order_footnote')}</TileNote>
            <TileActions>
                <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setRetainer(true)}>
                    {t('Add a retainer')}
                </SoloButton>
            </TileActions>
            <RetainerDialog open={retainer} onOpenChange={setRetainer} clientId={clientId} clientCurrency={currency} retainers={[]} activeIds={[]} sheet />
            {project !== null && <ProjectFormDialog open={editing} onOpenChange={setEditing} initial={project} />}
        </Tile>
    );
}

/** The reports of a client without a retainer: each opens its page, and one for the month just ended can be generated. */
export function OrderReports({
    clientId,
    reports,
    period,
    composers,
    locale,
}: {
    clientId: number;
    reports: OrderReportRow[];
    period: string;
    composers: ComposerOption[];
    locale: string;
}) {
    const { t } = useTranslation();
    const [generating, setGenerating] = useState(false);

    return (
        <Tile kind={reports.length === 0 ? 'plain' : 'list'}>
            <TileHeader
                label={t('Reports')}
                count={reports.length > 0 ? reports.length : undefined}
                end={
                    <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => setGenerating(true)}>
                        {t('Generate a report')}
                    </SoloButton>
                }
            />
            {reports.length === 0 ? (
                <TileNote>{t('No reports yet.')}</TileNote>
            ) : (
                <TileRows>
                    {reports.map((report, index) => (
                        <Row
                            key={report.id}
                            rule={index > 0}
                            title={t('Report for {{month}}', { month: monthName(report.period, locale) })}
                            href={clientReports.edit([clientId, report.id]).url}
                            meta={t(statusLabelKey(report.status)).toLocaleLowerCase(locale)}
                        />
                    ))}
                </TileRows>
            )}
            <GenerateReportDialog open={generating} onOpenChange={setGenerating} clientId={clientId} composers={composers} period={period} />
        </Tile>
    );
}

/** Figma: Miesiące. A client without a bank has no ledger, so its months stand alone in both values; Zmień opens the month's billed time. */
export function OrderMonths({ months, locale, onOpen }: { months: BilledMonthRow[]; locale: string; onOpen: (period: string) => void }) {
    const { t } = useTranslation();

    return (
        <Tile kind="list">
            <TileHeader label={t('Months')} end={<span className={styles.caption}>{t('real · billed')}</span>} />
            <TileRows>
                {months.map((month, index) => {
                    const tip = billedTip(month.billed, locale, t);

                    return (
                        <Row
                            key={month.period}
                            rule={index > 0}
                            title={
                                <>
                                    {capitalize(monthName(month.period, locale))}
                                    {tip !== null && (
                                        <>
                                            {' '}
                                            <InfoHint label={t('Your figure')}>{tip}</InfoHint>
                                        </>
                                    )}
                                </>
                            }
                            time={bothValues(month.billed, locale)}
                            action={
                                <SoloButton variant="secondary" size="control" aria-haspopup="dialog" onClick={() => onOpen(month.period)}>
                                    {month.billed_editable ? t('Change') : t('See')}
                                </SoloButton>
                            }
                        />
                    );
                })}
            </TileRows>
        </Tile>
    );
}
