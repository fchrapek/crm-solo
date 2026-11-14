import { Chip } from '@/components/solo/chip';
import { type ClientHeaderData } from '@/components/solo/client-header';
import { RecordFrame } from '@/components/solo/record-frame';
import { Pills } from '@/components/solo/shell/pills';
import { TileGrid, TileStack } from '@/components/solo/tile';
import { capitalize, monthName } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { localeOf } from '../client-list/format';
import { CloseMonthDialog, ReopenMonthDialog } from '../month-settlement';
import { RetainerDialog } from '../retainer-chip';
import { BilledTimeDialog } from './billed-time-dialog';
import styles from './client-billing.module.css';
import { monthNote, monthPills } from './format';
import { MonthInvoices } from './invoice-drafts';
import { InvoiceList } from './invoice-list';
import { LedgerTile } from './ledger-tile';
import { EmailTile, MonthStats, MonthTasks, MonthTile, ReportTile, RetainerTile } from './month-tiles';
import { OpeningBalanceDialog } from './opening-balance-dialog';
import { OrderMonths, orderNote, OrderReports, OrderStats, OrderTile } from './order-billing';
import type { SummaryEmails } from './summary-emails';
import type { BillingInvoiceList, BillingLedger, BillingMonth, ClientBilling, LedgerRow, RetainerBilling } from './types';

/** The opening balance edit is offered for the newest report while the rules let it change, and its row is on the page. */
export function openingRowOf(billing: RetainerBilling, ledger: BillingLedger): LedgerRow | undefined {
    const opening = billing.opening_balance;

    return opening !== null ? ledger.rows.find((row) => row.report_id === opening.report_id) : undefined;
}

/** The month's row of the bank, when the loaded pages hold it. */
export function monthRow(ledger: BillingLedger, period: string): LedgerRow | undefined {
    return ledger.rows.find((row) => row.whole_month && row.period === period);
}

/** The month still open behind the one in progress, to name while the running month is shown. */
export function monthBehind(settlement: RetainerBilling['settlement']): string | null {
    return settlement.open_behind ? settlement.active_period : null;
}

interface FrameProps {
    client: ClientHeaderData;
    billing: ClientBilling;
    ledger: BillingLedger | undefined;
    month: BillingMonth | undefined;
}

/**
 * Figma: Klient · Rozliczenia, the head. The month shown is the poster with how it stands under it, and the months are
 * a row of pills beside it; an order's head is the order.
 */
export function BillingFrame({ client, billing, ledger, month }: FrameProps) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const chip =
        client.live_agent_minutes !== null ? (
            <Chip status="agent">{t('{{agent}} working · {{minutes}} min', { agent: 'claude', minutes: client.live_agent_minutes })}</Chip>
        ) : undefined;
    const archived = client.is_archived ? t('Archived client') : null;

    if (billing.mode === 'order' || month === undefined || ledger === undefined) {
        return (
            <RecordFrame
                title={billing.mode === 'order' ? t('Order') : client.short_name}
                titleAttr={client.name}
                chip={chip}
                noteKind="line"
                note={[archived, billing.mode === 'order' ? orderNote(billing.order, locale, t) : null].filter(Boolean).join(' · ') || undefined}
            />
        );
    }

    const current = billing.settlement.current_period;

    return (
        <RecordFrame
            title={capitalize(monthName(month.period, locale))}
            chip={chip}
            noteKind="line"
            note={[archived, monthNote(month, current, monthBehind(billing.settlement), locale, t)].filter(Boolean).join(' · ')}
            aside={
                <>
                    <span className={styles.pillsLabel}>{t('Month')}</span>
                    <Pills items={monthPills(ledger.rows, month.period, current, client.id, locale, t)} label={t('Months')} />
                </>
            }
        />
    );
}

type Dialog = 'retainer' | 'opening' | 'close' | 'reopen' | null;

/**
 * Figma: Klient · Rozliczenia (1087:4031, phone 1087:4619; an order 1091:17551). One month at a time, the one in the
 * URL: its figures, tasks, report, invoices and settlement, then the bank across months and every invoice.
 */
export function ClientBillingTab({
    clientId,
    clientName,
    billing,
    ledger,
    month,
    invoices,
    emails = null,
}: {
    clientId: number;
    clientName: string;
    billing: ClientBilling;
    ledger: BillingLedger | undefined;
    month: BillingMonth | undefined;
    invoices: BillingInvoiceList | undefined;
    /** A summary-email client's month emails: the month shows its email where others show the report. */
    emails?: SummaryEmails | null;
}) {
    const { i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [billed, setBilled] = useState<{ period: string; task: number | null } | null>(null);
    const thisYear = new Date().getFullYear();

    const billedDialog =
        billed !== null ? (
            <BilledTimeDialog
                clientId={clientId}
                period={billed.period}
                task={billed.task}
                open
                onOpenChange={(open) => !open && setBilled(null)}
                onReopen={(locked) => {
                    setBilled(null);
                    if (locked.closure_id !== null) {
                        setDialog('reopen');
                    } else if (locked.report !== null) {
                        router.visit(clientReports.edit([clientId, locked.report.id]).url);
                    }
                }}
            />
        ) : null;

    if (billing.mode === 'order') {
        return (
            <TileStack>
                <OrderStats order={billing.order} locale={locale} />
                <TileGrid>
                    <TileStack>
                        <OrderTile clientId={clientId} order={billing.order} currency={billing.currency} locale={locale} />
                        <OrderReports clientId={clientId} reports={billing.reports} period={billing.report_period} composers={billing.composers} locale={locale} />
                    </TileStack>
                    <TileStack as="aside">
                        {billing.billed_months.length > 0 && (
                            <OrderMonths months={billing.billed_months} locale={locale} onOpen={(period) => setBilled({ period, task: null })} />
                        )}
                    </TileStack>
                </TileGrid>
                {invoices !== undefined && <InvoiceList clientId={clientId} period={null} list={invoices} thisYear={thisYear} locale={locale} />}
                {billedDialog}
            </TileStack>
        );
    }

    if (ledger === undefined || month === undefined) {
        return null;
    }

    const figures = month.figures;
    const current = billing.settlement.current_period;
    const running = month.period === current;
    const row = monthRow(ledger, month.period);
    const openingRow = openingRowOf(billing, ledger);
    const reopenRow = ledger.rows.find((candidate) => candidate.closure_id !== null);
    const drafts = billing.invoice_drafts !== null && billing.invoice_drafts.period === month.period ? billing.invoice_drafts : null;
    // What locks the month's figures can be reopened from here: the latest closure, or the report on its own page.
    const reopenLocked =
        figures.closure_id !== null && figures.closure_id === reopenRow?.closure_id
            ? () => setDialog('reopen')
            : figures.closure_id === null && figures.refusal_code === 'not_a_draft' && month.report !== null
              ? () => router.visit(clientReports.edit([clientId, month.report?.id ?? 0]).url)
              : undefined;

    return (
        <TileStack>
            <MonthStats month={month} running={running} locale={locale} />
            <TileGrid className={styles.level}>
                <TileStack>
                    <MonthTasks month={month} locale={locale} onChange={(task) => setBilled({ period: month.period, task: task.task_id })} onReopen={reopenLocked} />
                    <MonthInvoices clientId={clientId} clientName={clientName} period={month.period} drafts={drafts} invoices={month.invoices} locale={locale} />
                    <RetainerTile retainer={billing.retainer} locale={locale} onChange={() => setDialog('retainer')} />
                </TileStack>
                <TileStack as="aside">
                    {(emails === null || month.report !== null) && <ReportTile clientId={clientId} month={month} running={running} locale={locale} />}
                    {emails !== null && (
                        <EmailTile
                            clientId={clientId}
                            clientName={clientName}
                            period={month.period}
                            state={emails[month.period]}
                            due={!running && !figures.closed}
                            locale={locale}
                        />
                    )}
                    <MonthTile
                        clientId={clientId}
                        month={month}
                        row={row}
                        running={running}
                        behind={running ? monthBehind(billing.settlement) : null}
                        locale={locale}
                        onClose={() => setDialog('close')}
                        onReopen={() => setDialog('reopen')}
                    />
                </TileStack>
            </TileGrid>
            <LedgerTile
                clientId={clientId}
                ledger={ledger}
                cap={billing.cap}
                shown={month.period}
                openingReportId={openingRow !== undefined ? openingRow.report_id : null}
                emails={emails}
                locale={locale}
                onOpening={() => setDialog('opening')}
                onReopen={() => setDialog('reopen')}
            />
            {invoices !== undefined && <InvoiceList clientId={clientId} period={month.period} list={invoices} thisYear={thisYear} locale={locale} />}
            {billedDialog}
            <CloseMonthDialog clientId={clientId} period={month.period} open={dialog === 'close'} onOpenChange={(open) => setDialog(open ? 'close' : null)} />
            {reopenRow !== undefined && reopenRow.closure_id !== null && (
                <ReopenMonthDialog
                    clientId={clientId}
                    clientName={clientName}
                    target={{
                        period: reopenRow.period,
                        closure_id: reopenRow.closure_id,
                        used: reopenRow.used,
                        closing: reopenRow.closing,
                        refusal: reopenRow.reopen_refusal,
                        blocker: reopenRow.reopen_blocker,
                    }}
                    open={dialog === 'reopen'}
                    onOpenChange={(open) => setDialog(open ? 'reopen' : null)}
                />
            )}
            {billing.opening_balance !== null && openingRow !== undefined && (
                <OpeningBalanceDialog
                    clientId={clientId}
                    reportId={billing.opening_balance.report_id}
                    row={openingRow}
                    value={billing.opening_balance.value}
                    version={billing.opening_balance.version}
                    open={dialog === 'opening'}
                    onOpenChange={(open) => setDialog(open ? 'opening' : null)}
                />
            )}
            <RetainerDialog
                open={dialog === 'retainer'}
                onOpenChange={(open) => setDialog(open ? 'retainer' : null)}
                clientId={clientId}
                clientCurrency={billing.retainer.currency}
                retainers={billing.retainer.positions}
                activeIds={billing.retainer.active_ids}
                sheet
            />
        </TileStack>
    );
}
