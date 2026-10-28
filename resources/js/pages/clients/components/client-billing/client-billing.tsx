import { KvRow } from '@/components/solo/kv-row';
import { BodyLink, SectionAction, SectionHead, SoloButtonLink } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { capitalize, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import { Link } from '@inertiajs/react';
import { Fragment, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, hoursTerms, localeOf } from '../client-list/format';
import { reportFlowUrl } from '../client-overview';
import { ReopenMonthDialog } from '../month-settlement';
import { RetainerDialog } from '../retainer-chip/retainer-dialog';
import styles from './client-billing.module.css';
import { invoiceMonth, invoiceState, invoiceTitle, moneyOf, periodLabel, positionSummary } from './format';
import { OpeningBalanceDialog } from './opening-balance-dialog';
import { OrderBrief, OrderSection } from './order-billing';
import { PhoneInvoicesSection, PhoneRetainerBilling } from './phone-billing';
import type { BillingInvoice, BillingLedger, ClientBilling, LedgerRow, RetainerBilling } from './types';

type Translate = ReturnType<typeof useTranslation>['t'];

export type BillingDialog = 'retainer' | 'opening' | null;

/** The opening balance edit is offered for the newest report while the rules let it change, and its row is on the page. */
export function openingRowOf(billing: RetainerBilling, ledger: BillingLedger): LedgerRow | undefined {
    const opening = billing.opening_balance;

    return opening !== null ? ledger.rows.find((row) => row.report_id === opening.report_id) : undefined;
}

/** Figma: Klient / Rozliczenia, and below 768 px 334:3308 (an order 335:3956), both trees from the same props. */
export function ClientBillingTab({
    clientId,
    clientName,
    billing,
    ledger,
    dialog,
    onDialogChange,
}: {
    clientId: number;
    clientName: string;
    billing: ClientBilling;
    ledger: BillingLedger | undefined;
    dialog: BillingDialog;
    onDialogChange: (dialog: BillingDialog) => void;
}) {
    const { i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [reopening, setReopening] = useState<'table' | 'sheet' | null>(null);
    const [bankOpen, setBankOpen] = useState(false);

    if (billing.mode === 'order') {
        return (
            <>
                <div className={styles.wide}>
                    <OrderBrief order={billing.order} locale={locale} />
                </div>
                <div className={`${splitClass} ${styles.body}`}>
                    <OrderSection order={billing.order} locale={locale} />
                    <div className={`${splitSideClass} ${styles.rail}`}>
                        <div className={styles.wide}>
                            <InvoicesSection clientId={clientId} invoices={billing.invoices} basis="gross" locale={locale} />
                        </div>
                        <PhoneInvoicesSection clientId={clientId} invoices={billing.invoices} basis="gross" locale={locale} />
                    </div>
                </div>
            </>
        );
    }

    if (ledger === undefined) {
        return null;
    }

    const openingRow = openingRowOf(billing, ledger);
    const reopenRow = ledger.rows.find((row) => row.closure_id !== null);

    function reopen(from: 'table' | 'sheet') {
        if (from === 'sheet') {
            setBankOpen(false);
        }
        setReopening(from);
    }

    return (
        <>
            <div className={styles.wide}>
                <BillingBrief billing={billing} rows={ledger.rows} locale={locale} />
                <div className={`${splitClass} ${styles.body}`}>
                    <HoursBankSection
                        clientId={clientId}
                        billing={billing}
                        ledger={ledger}
                        locale={locale}
                        onOpening={openingRow !== undefined ? () => onDialogChange('opening') : undefined}
                        onReopen={() => reopen('table')}
                    />
                    <div className={`${splitSideClass} ${styles.rail}`}>
                        <RetainerSection retainer={billing.retainer} locale={locale} onChange={() => onDialogChange('retainer')} />
                        <InvoicesSection clientId={clientId} invoices={billing.invoices} locale={locale} />
                    </div>
                </div>
            </div>
            <PhoneRetainerBilling
                clientId={clientId}
                billing={billing}
                ledger={ledger}
                locale={locale}
                bankOpen={bankOpen}
                onBankOpenChange={setBankOpen}
                onReopen={() => reopen('sheet')}
            />
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
                    open={reopening !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            // The phone flow returns to the ledger it started from, after a reopen or a cancel.
                            setBankOpen(reopening === 'sheet');
                            setReopening(null);
                        }
                    }}
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
                    onOpenChange={(open) => onDialogChange(open ? 'opening' : null)}
                />
            )}
            <RetainerDialog
                open={dialog === 'retainer'}
                onOpenChange={(open) => onDialogChange(open ? 'retainer' : null)}
                clientId={clientId}
                clientCurrency={billing.retainer.currency}
                retainers={billing.retainer.positions}
                activeIds={billing.retainer.active_ids}
                sheet
            />
        </>
    );
}

function Accent({ children }: { children: ReactNode }) {
    return <span className={styles.accent}>{children}</span>;
}

function BillingBrief({ billing, rows, locale }: { billing: RetainerBilling; rows: LedgerRow[]; locale: string }) {
    const { t } = useTranslation();
    const row = rows[0];
    const closed = rows.find((candidate) => candidate.settlement === 'closed');
    const active = rows.find((candidate) => candidate.active);
    const invoice = billing.invoices.rows[0];

    // Closure first (F5): the latest closed month and the one now open say where the bank stands.
    if (closed !== undefined && active !== undefined && active.period > closed.period) {
        return (
            <p className={styles.brief}>
                {closedClause(closed, locale, t)} {openClause(active, locale, t)}
            </p>
        );
    }

    const report = reportClause(billing, rows, locale, t);
    const invoiceText = invoice !== undefined ? invoiceClause(invoice, locale, t) : null;

    if (row === undefined && invoiceText === null) {
        return <p className={styles.brief}>{t('No reports or invoices yet.')}</p>;
    }

    return (
        <p className={styles.brief}>
            {row !== undefined && monthClause(row, locale, t)}
            {(report !== null || invoiceText !== null) && row !== undefined && ' '}
            {report}
            {report !== null && invoiceText !== null && ', '}
            {invoiceText !== null && (
                <>
                    {report === null ? capitalize(invoiceText.lead) : invoiceText.lead}
                    {invoiceText.accent !== undefined && (
                        <>
                            {' '}
                            <Accent>{invoiceText.accent}</Accent>
                        </>
                    )}
                </>
            )}
            {(report !== null || invoiceText !== null) && '.'}
        </p>
    );
}

function balanceText(closing: number, locale: string): ReactNode {
    const balance = `${hoursNumber(closing, locale, true)}\u00a0h`;

    return closing < 0 ? <Accent>{balance}</Accent> : balance;
}

function closedClause(row: LedgerRow, locale: string, t: Translate): ReactNode {
    const values = {
        month: capitalize(monthName(row.period, locale)),
        date: row.closed_on !== null ? shortDate(row.closed_on, locale) : '',
        used: hoursNumber(row.used, locale),
        contracted: row.contracted !== null ? hoursTerms(row.contracted, locale) : '',
    };
    const key =
        row.contracted !== null
            ? '{{month}} closed {{date}} at {{used}} of {{contracted}} h, the bank after the month is'
            : '{{month}} closed {{date}} at {{used}} h, the bank after the month is';

    return (
        <>
            {t(key, values)} {balanceText(row.closing, locale)}.
        </>
    );
}

function openClause(row: LedgerRow, locale: string, t: Translate): string {
    return t('{{month}} open: {{used}} of {{available}} h.', {
        month: capitalize(monthName(row.period, locale)),
        used: hoursNumber(row.used, locale),
        available: hoursNumber(row.available, locale),
    });
}

function monthClause(row: LedgerRow, locale: string, t: Translate): ReactNode {
    const month = capitalize(monthName(row.period, locale));
    const used = hoursNumber(row.used, locale);
    const values = { month, used, contracted: row.contracted !== null ? hoursTerms(row.contracted, locale) : '' };
    // An ended month is closed only when a closure says so; otherwise it simply ended.
    const key = row.ended
        ? row.contracted !== null
            ? '{{month}} ended at {{used}} of {{contracted}} h, the bank after the month is'
            : '{{month}} ended at {{used}} h, the bank after the month is'
        : row.contracted !== null
          ? '{{month}} so far: {{used}} of {{contracted}} h, the bank today is'
          : '{{month}} so far: {{used}} h, the bank today is';

    return (
        <>
            {t(key, values)} {balanceText(row.closing, locale)}.
        </>
    );
}

function reportClause(billing: RetainerBilling, rows: LedgerRow[], locale: string, t: Translate): ReactNode {
    const waiting = billing.waiting_report;
    if (waiting !== null) {
        return (
            <>
                {t('Report for {{month}}', { month: monthName(waiting.period, locale) })} <Accent>{t('is waiting to be sent')}</Accent>
            </>
        );
    }

    const latest = rows.find((row) => row.report_id !== null);
    if (latest !== undefined && latest.status === 'sent' && latest.sent_on !== null) {
        return t('Report for {{month}} sent {{date}}', { month: monthName(latest.period, locale), date: shortDate(latest.sent_on, locale) });
    }

    return null;
}

function invoiceClause(invoice: BillingInvoice, locale: string, t: Translate): { lead: string; accent?: string } {
    const month = invoiceMonth(invoice.sale_date, invoice.invoice_date, locale) ?? '';

    if (invoice.status === 'paid') {
        return {
            lead:
                invoice.paid_date !== null
                    ? t('invoice for {{month}} paid {{date}}', { month, date: shortDate(invoice.paid_date, locale) })
                    : t('invoice for {{month}} paid', { month }),
        };
    }

    if (invoice.overdue && invoice.payment_date !== null) {
        return {
            lead: t('invoice for {{month}}', { month }),
            accent: t('overdue since {{date}}', { date: shortDate(invoice.payment_date, locale) }),
        };
    }

    if (invoice.status === 'draft') {
        return { lead: t('invoice for {{month}} is a draft in Infakt', { month }) };
    }

    return {
        lead:
            invoice.payment_date !== null
                ? t('invoice for {{month}} due {{date}}', { month, date: shortDate(invoice.payment_date, locale) })
                : t('invoice for {{month}} is unpaid', { month }),
    };
}

function HoursBankSection({
    clientId,
    billing,
    ledger,
    locale,
    onOpening,
    onReopen,
}: {
    clientId: number;
    billing: RetainerBilling;
    ledger: BillingLedger;
    locale: string;
    onOpening: (() => void) | undefined;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    const { rows, start, next } = ledger;
    const cap = billing.cap;
    const newest = rows[0];

    return (
        <section className={`${splitMainClass} ${styles.main}`}>
            <SectionHead
                kind="body"
                label={t('Hours bank')}
                action={onOpening !== undefined ? <SectionAction onClick={onOpening}>{t('Change opening balance')}</SectionAction> : undefined}
            />
            {newest === undefined ? (
                <div className={styles.emptyBank}>
                    <p className={styles.empty}>
                        {t('The hours bank starts with the first report. Generate one, then set the opening balance on it.')}
                    </p>
                    <BodyLink href={reportFlowUrl(clientId)}>{t('Generate a report')} →</BodyLink>
                </div>
            ) : (
                <>
                    <div className={styles.ledger} role="table" aria-label={t('Hours bank')}>
                        <div className={styles.ledgerHead} role="row">
                            <span role="columnheader">{t('ledger_month')}</span>
                            <span role="columnheader">{t('ledger_package')}</span>
                            <span role="columnheader">{t('ledger_used')}</span>
                            <span role="columnheader">{t('ledger_change')}</span>
                            <span role="columnheader">{t('ledger_balance')}</span>
                            <span role="columnheader">{t('ledger_report')}</span>
                        </div>
                        {rows.map((row, index) => {
                            const older = rows[index + 1];
                            // The opening was set by hand when it does not continue the month before; show the step so the column adds up.
                            const correction =
                                older !== undefined && Math.abs(row.opening - older.closing) >= 0.005 ? row.opening - older.closing : null;

                            return (
                                <Fragment key={row.key}>
                                    <div className={styles.ledgerRow} role="row">
                                        <span role="cell" className={styles.month}>
                                            {periodLabel(row, rows[index - 1], locale)}
                                        </span>
                                        <span role="cell">{row.contracted !== null ? `${hoursTerms(row.contracted, locale)} h` : ''}</span>
                                        <span role="cell">{`${hoursNumber(row.used, locale)} h`}</span>
                                        <span role="cell">{hoursNumber(row.change, locale, true)}</span>
                                        <span role="cell" data-tone={index === 0 && row.closing < 0 ? 'accent' : undefined}>
                                            {`${hoursNumber(row.closing, locale, true)} h`}
                                        </span>
                                        <span role="cell" className={styles.report}>
                                            <RowState clientId={clientId} row={row} locale={locale} onReopen={onReopen} />
                                        </span>
                                    </div>
                                    {correction !== null && (
                                        <div className={`${styles.ledgerRow} ${styles.correction}`} role="row">
                                            <span role="cell" className={styles.month}>
                                                {t('Correction {{date}}', { date: shortDate(row.period_start, locale) })}
                                            </span>
                                            <span role="cell" />
                                            <span role="cell" />
                                            <span role="cell">{hoursNumber(correction, locale, true)}</span>
                                            <span role="cell">{`${hoursNumber(row.opening, locale, true)} h`}</span>
                                            <span role="cell" className={styles.report} />
                                        </div>
                                    )}
                                </Fragment>
                            );
                        })}
                        {start !== null && (
                            <div className={styles.ledgerRow} role="row">
                                <span role="cell" className={styles.month}>
                                    {t('Start {{date}}', { date: shortDate(start.date, locale) })}
                                </span>
                                <span role="cell" />
                                <span role="cell" />
                                <span role="cell" />
                                <span role="cell">{`${hoursNumber(start.balance, locale, true)} h`}</span>
                                <span role="cell" className={styles.report}>
                                    <span className={styles.muted}>{t('ledger_start')}</span>
                                </span>
                            </div>
                        )}
                    </div>
                    <p className={styles.footnote}>
                        {cap !== null
                            ? t('The balance is what carries into the next month. Rollover up to {{cap}} h.', { cap: hoursTerms(cap, locale) })
                            : t('The balance is what carries into the next month.')}
                    </p>
                    {next !== null && (
                        <div className={styles.more}>
                            <BodyLink
                                href={clients.show({ client: clientId, tab: 'rozliczenia' }).url}
                                data={{ miesiace: next }}
                                only={['billing_ledger']}
                                preserveUrl
                                preserveScroll
                                preserveState
                            >
                                {t('Older')} →
                            </BodyLink>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

/** The month's settlement over its report's delivery, kept apart; "Otwórz ponownie" only on the latest closed month. */
function RowState({ clientId, row, locale, onReopen }: { clientId: number; row: LedgerRow; locale: string; onReopen: () => void }) {
    const { t } = useTranslation();

    return (
        <span className={styles.states}>
            {row.settlement === 'closed' && (
                <span className={styles.muted}>
                    {row.closed_on !== null ? t('ledger_closed', { date: shortDate(row.closed_on, locale) }) : t('ledger_closed_undated')}
                </span>
            )}
            {row.settlement === 'open' && (
                <span className={styles.muted}>
                    {row.report_id === null
                        ? t('ledger_open_from', { balance: `${hoursNumber(row.opening, locale, true)}\u00a0h` })
                        : t('ledger_open')}
                </span>
            )}
            {row.report_id !== null && row.status !== null && <ReportState clientId={clientId} reportId={row.report_id} row={row} locale={locale} />}
            {row.closure_id !== null && (
                <button type="button" className={styles.reopen} onClick={onReopen}>
                    {t('Reopen the month')}
                </button>
            )}
        </span>
    );
}

function ReportState({ clientId, reportId, row, locale }: { clientId: number; reportId: number; row: LedgerRow; locale: string }) {
    const { t } = useTranslation();
    const href = clientReports.edit([clientId, reportId]).url;
    // Beside a settlement line the report names itself, so "wysłany" cannot read as the month's state.
    const prefixed = row.settlement !== null;

    if (row.status === 'draft') {
        return (
            <SoloButtonLink variant="ink" size="sm" href={href}>
                {t('Draft · send')}
            </SoloButtonLink>
        );
    }

    const label =
        row.status === 'sent'
            ? row.sent_on !== null
                ? t(prefixed ? 'ledger_report_sent_named' : 'ledger_report_sent', { date: shortDate(row.sent_on, locale) })
                : t(prefixed ? 'ledger_report_sent_undated_named' : 'ledger_report_sent_undated')
            : t(prefixed ? 'ledger_report_finalized_named' : 'ledger_report_finalized');

    return (
        <Link href={href} className={styles.reportLink}>
            {label}
        </Link>
    );
}

function RetainerSection({ retainer, locale, onChange }: { retainer: RetainerBilling['retainer']; locale: string; onChange: () => void }) {
    const { t } = useTranslation();
    const several = retainer.active.length > 1;

    return (
        <section className={styles.railSection}>
            <SectionHead
                kind="body"
                label={t('Retainer')}
                action={<SectionAction onClick={onChange}>{retainer.active.length > 0 ? t('Change') : t('Add')}</SectionAction>}
            />
            {retainer.active.length === 0 ? (
                <p className={styles.empty}>{t('No retainer, so every logged hour counts.')}</p>
            ) : (
                <dl className={styles.list}>
                    {retainer.active.map((position) => (
                        <KvRow key={position.id} layout="split" weight="medium" label={positionSummary(position, several, locale, t)}>
                            {position.monthly_fee !== null
                                ? t('{{money}} net / mo', { money: moneyOf(position.monthly_fee, position.currency, locale, false) })
                                : '·'}
                        </KvRow>
                    ))}
                </dl>
            )}
        </section>
    );
}

/** The retainer rail reads net, as the fee does; an order reads gross, as it is agreed and paid. */
function InvoicesSection({
    clientId,
    invoices,
    basis = 'net',
    locale,
}: {
    clientId: number;
    invoices: ClientBilling['invoices'];
    basis?: 'net' | 'gross';
    locale: string;
}) {
    const { t } = useTranslation();

    return (
        <section className={styles.railSection}>
            <SectionHead kind="body" label={t('Invoices · Infakt')} />
            {invoices.rows.length === 0 ? (
                <p className={styles.empty}>{t('No invoices from Infakt yet.')}</p>
            ) : (
                <ul className={styles.list}>
                    {invoices.rows.map((invoice) => (
                        <InvoiceRow key={invoice.id} invoice={invoice} basis={basis} locale={locale} />
                    ))}
                </ul>
            )}
            {invoices.more > 0 && (
                <div className={styles.more}>
                    <BodyLink href={`${clients.edit(clientId, { query: { invoice_year: 'all' } }).url}#invoices`}>
                        {t('{{count}} more', { count: invoices.more })} →
                    </BodyLink>
                </div>
            )}
        </section>
    );
}

/** Figma: Invoice row. */
function InvoiceRow({ invoice, basis, locale }: { invoice: BillingInvoice; basis: 'net' | 'gross'; locale: string }) {
    const { t } = useTranslation();
    const title = invoiceTitle(invoice, locale, t);

    return (
        <li className={styles.invoice}>
            <div className={styles.invoiceTitle}>
                {invoice.external_url !== null ? (
                    <a href={invoice.external_url} target="_blank" rel="noopener noreferrer" title={t('Open in Infakt')}>
                        {title}
                    </a>
                ) : (
                    <span>{title}</span>
                )}
                <span className={styles.amount}>{moneyOf(invoice[basis], invoice.currency, locale, true)}</span>
            </div>
            <p className={styles.invoiceState} data-tone={invoice.overdue ? 'accent' : undefined}>
                {invoiceState(invoice, locale, t)}
            </p>
        </li>
    );
}
