import { Chip } from '@/components/solo/chip';
import { type ClientHeaderData } from '@/components/solo/client-header';
import { RecordFrame } from '@/components/solo/record-frame';
import { Pills } from '@/components/solo/shell/pills';
import { TileGrid, TileSlot, TileStack } from '@/components/solo/tile';
import { capitalize, monthName } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import { router } from '@inertiajs/react';
import { type ComponentProps, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { localeOf } from '../client-list/format';
import { CloseMonthDialog, ReopenMonthDialog } from '../month-settlement';
import { RetainerDialog } from '../retainer-chip';
import { BilledTimeDialog } from './billed-time-dialog';
import styles from './client-billing.module.css';
import { monthPills } from './format';
import { InvoiceDraftsTile } from './invoice-drafts';
import { InvoiceList } from './invoice-list';
import { LedgerTile } from './ledger-tile';
import { BaselineTile, EmailTile, MonthStats, MonthStatus, MonthTasks, ReportTile, RetainerTile } from './month-tiles';
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

/** What Reopen the month reopens: the month shown when its closure is the latest, else the latest closed month of the bank's rows. */
export function reopenTargetOf(month: BillingMonth, ledger: BillingLedger): ReopenTarget | null {
    const own = month.settlement;
    if (own.closure_id !== null) {
        return {
            period: month.period,
            closure_id: own.closure_id,
            used: month.figures.used,
            closing: month.figures.closing,
            refusal: own.reopen_refusal,
            blocker: own.reopen_blocker,
        };
    }
    const row = ledger.rows.find((candidate) => candidate.closure_id !== null);

    return row !== undefined && row.closure_id !== null
        ? {
              period: row.period,
              closure_id: row.closure_id,
              used: row.used,
              closing: row.closing,
              refusal: row.reopen_refusal,
              blocker: row.reopen_blocker,
          }
        : null;
}

type ReopenTarget = ComponentProps<typeof ReopenMonthDialog>['target'];

/** An earlier month still open, which closes before the month shown. */
export function monthEarlier(settlement: RetainerBilling['settlement'], period: string): string | null {
    return settlement.active_period < period ? settlement.active_period : null;
}

interface FrameProps {
    client: ClientHeaderData;
    billing: ClientBilling;
    ledger: BillingLedger | undefined;
    month: BillingMonth | undefined;
}

/**
 * Figma: Client · Billing, the head. The month shown is the poster and the months are a row of pills beside it; how
 * the month stands is the tab's first line (MonthStatus). An order's head is the order.
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
                titleSize={billing.mode === 'order' ? 'heading' : 'record'}
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
            note={archived ?? undefined}
            aside={
                <>
                    <span className={styles.pillsLabel}>{t('Month')}</span>
                    <Pills items={monthPills(billing.months, month.period, current, client.id, locale, t)} label={t('Months')} />
                </>
            }
        />
    );
}

type Dialog = 'retainer' | 'opening' | 'close' | 'reopen' | null;

/**
 * Figma: Client · Billing (8C, 1449:21288, phone 1449:25206; an order 1091:17551). One month at a time, the one in
 * the URL: its state and figures, then two columns, the tasks, the bank and every invoice beside the report, the
 * invoices to draft, the retainer, the email and the report baseline. A phone has one column in its own order.
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
                <TileGrid stickySide={false}>
                    <TileStack>
                        <OrderTile clientId={clientId} order={billing.order} currency={billing.currency} locale={locale} />
                        <OrderReports
                            clientId={clientId}
                            reports={billing.reports}
                            period={billing.report_period}
                            composers={billing.composers}
                            locale={locale}
                        />
                    </TileStack>
                    <TileStack as="aside">
                        {billing.billed_months.length > 0 && (
                            <OrderMonths months={billing.billed_months} locale={locale} onOpen={(period) => setBilled({ period, task: null })} />
                        )}
                        {billing.report_baseline !== null && <BaselineTile clientId={clientId} baseline={billing.report_baseline} />}
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
    const openingRow = openingRowOf(billing, ledger);
    const reopenTarget = reopenTargetOf(month, ledger);
    const drafts = billing.invoice_drafts !== null && billing.invoice_drafts.period === month.period ? billing.invoice_drafts : null;
    const bank = figures.has_retainer || ledger.rows.length > 0;
    // A closed month reopens from its line under the name; a report that locks the month goes back to a draft on its own page.
    const reopenReport =
        figures.closure_id === null && figures.refusal_code === 'not_a_draft' && month.report !== null
            ? () => router.visit(clientReports.edit([clientId, month.report?.id ?? 0]).url)
            : undefined;

    return (
        <TileStack>
            <MonthStatus
                clientId={clientId}
                month={month}
                running={running}
                current={current}
                earlier={monthEarlier(billing.settlement, month.period)}
                locale={locale}
                onClose={() => setDialog('close')}
                onReopen={() => setDialog('reopen')}
            />
            <MonthStats month={month} running={running} locale={locale} />
            <TileGrid reflow stickySide={false}>
                <TileStack>
                    <TileSlot order={1}>
                        <MonthTasks
                            month={month}
                            locale={locale}
                            onChange={(task) => setBilled({ period: month.period, task: task.task_id })}
                            onReopen={reopenReport}
                        />
                    </TileSlot>
                    {bank && (
                        <TileSlot order={4}>
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
                        </TileSlot>
                    )}
                    {invoices !== undefined && (
                        <TileSlot order={5}>
                            <InvoiceList
                                clientId={clientId}
                                period={month.period}
                                month={{ period: month.period, invoices: month.invoices, drafting: drafts !== null }}
                                list={invoices}
                                thisYear={thisYear}
                                locale={locale}
                            />
                        </TileSlot>
                    )}
                </TileStack>
                <TileStack as="aside">
                    {(emails === null || month.report !== null) && (
                        <TileSlot order={2}>
                            <ReportTile clientId={clientId} month={month} running={running} locale={locale} />
                        </TileSlot>
                    )}
                    {drafts !== null && (
                        <TileSlot order={3}>
                            <InvoiceDraftsTile clientId={clientId} clientName={clientName} drafts={drafts} locale={locale} />
                        </TileSlot>
                    )}
                    <TileSlot order={6}>
                        <RetainerTile retainer={billing.retainer} locale={locale} onChange={() => setDialog('retainer')} />
                    </TileSlot>
                    {emails !== null && (
                        <TileSlot order={7}>
                            <EmailTile
                                clientId={clientId}
                                clientName={clientName}
                                period={month.period}
                                state={emails[month.period]}
                                due={!running && !figures.closed}
                                locale={locale}
                            />
                        </TileSlot>
                    )}
                    {billing.report_baseline !== null && (
                        <TileSlot order={8}>
                            <BaselineTile clientId={clientId} baseline={billing.report_baseline} />
                        </TileSlot>
                    )}
                </TileStack>
            </TileGrid>
            {billedDialog}
            <CloseMonthDialog
                clientId={clientId}
                period={month.period}
                open={dialog === 'close'}
                onOpenChange={(open) => setDialog(open ? 'close' : null)}
            />
            {reopenTarget !== null && (
                <ReopenMonthDialog
                    clientId={clientId}
                    clientName={clientName}
                    target={reopenTarget}
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
