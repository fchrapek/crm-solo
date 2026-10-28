import { BottomSheet } from '@/components/solo/bottom-sheet';
import { KvRow } from '@/components/solo/kv-row';
import { ListRow, ListRowStatic } from '@/components/solo/list-row';
import { LateDotIcon } from '@/components/solo/mobile-icons';
import { BodyLink, SectionHead, SoloButton, SoloButtonLink, touchTargetClass } from '@/components/solo/primitives';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { capitalize, monthLocative, monthName, shortDate } from '@/lib/dates';
import clientReports from '@/routes/client-reports';
import clients from '@/routes/clients';
import { Link } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { Fragment, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, hoursTerms } from '../client-list/format';
import { reportFlowUrl } from '../client-overview';
import styles from './client-billing.module.css';
import { invoiceState, invoiceTitle, moneyOf, orderInvoiceLine, periodLabel, positionSummary } from './format';
import type { BillingInvoice, BillingLedger, ClientBilling, LedgerRow, RetainerBilling } from './types';

const h = (value: number, locale: string, signed = false) => `${hoursNumber(value, locale, signed)}\u00a0h`;

/** The month the poster reads: the latest closed one while a later month is open (the bank it left), else the newest. */
export function posterRow(rows: LedgerRow[]): LedgerRow | undefined {
    const closed = rows.find((row) => row.settlement === 'closed');
    const active = rows.find((row) => row.active);

    return closed !== undefined && active !== undefined && active.period > closed.period ? closed : rows[0];
}

/** "bank po wrześniu" after an ended month, "bank dziś" while it runs. */
export function posterLabel(row: LedgerRow, t: TFunction): string {
    return row.ended ? t('bank after {{month}}', { month: monthLocative(row.period, t) }) : t('bank today');
}

/** The note under the poster: the closure and the month now open (Figma 334:3308), a month left open behind, or the open month. */
export function posterNote(rows: LedgerRow[], settlement: RetainerBilling['settlement'], locale: string, t: TFunction): string | null {
    const closed = rows.find((row) => row.settlement === 'closed');
    const active = rows.find((row) => row.active);
    if (active === undefined) {
        return null;
    }

    const open = {
        month: monthName(active.period, locale),
        used: hoursNumber(active.used, locale),
        available: hoursNumber(active.available, locale),
    };
    if (closed !== undefined && active.period > closed.period) {
        const values = { ...open, Month: capitalize(monthName(closed.period, locale)) };

        return closed.closed_on !== null
            ? t('billing_note_closed', { ...values, date: shortDate(closed.closed_on, locale) })
            : t('billing_note_closed_undated', values);
    }

    if (settlement.open_behind && settlement.active_period === active.period) {
        return t('It is {{current}} now · {{month}} still open', { current: monthName(settlement.current_period, locale), month: open.month });
    }

    return t('billing_note_open', { ...open, Month: capitalize(open.month) });
}

/** An open month with nothing reported yet has no balance to show, only where it opened from. */
export function ledgerValue(row: LedgerRow, locale: string): string {
    return row.settlement === 'open' && row.report_id === null ? '·' : h(row.closing, locale, true);
}

export function ledgerSub(row: LedgerRow, locale: string, t: TFunction): string {
    return [
        row.contracted !== null ? t('ledger_sub_package', { hours: hoursTerms(row.contracted, locale) }) : null,
        t('ledger_sub_used', { hours: hoursNumber(row.used, locale) }),
        ledgerValue(row, locale) !== '·' ? t('ledger_sub_change', { change: hoursNumber(row.change, locale, true) }) : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Settlement first, then the report's delivery on its own line; a report beside a settlement names itself. */
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

/** Figma 334:3308: the balance poster, the report row and the latest invoice; the ledger and the invoices open as sheets. */
export function PhoneRetainerBilling({
    clientId,
    billing,
    ledger,
    locale,
    bankOpen,
    onBankOpenChange,
    onReopen,
}: {
    clientId: number;
    billing: RetainerBilling;
    ledger: BillingLedger;
    locale: string;
    bankOpen: boolean;
    onBankOpenChange: (open: boolean) => void;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const [invoicesOpen, setInvoicesOpen] = useState(false);
    const poster = posterRow(ledger.rows);
    const note = posterNote(ledger.rows, billing.settlement, locale, t);
    const invoice = billing.invoices.rows[0];

    return (
        <div className={styles.phone}>
            {poster === undefined ? (
                <div className={styles.phoneEmpty}>
                    <p className={styles.empty}>
                        {t('The hours bank starts with the first report. Generate one, then set the opening balance on it.')}
                    </p>
                    <BodyLink href={reportFlowUrl(clientId)}>{t('Generate a report')} →</BodyLink>
                </div>
            ) : (
                <div className={styles.poster}>
                    <p className={styles.posterValue} data-tone={poster.closing < 0 ? 'over' : undefined}>
                        {h(poster.closing, locale, true)}
                    </p>
                    <BottomSheet
                        open={bankOpen && !wide}
                        onOpenChange={onBankOpenChange}
                        title={t('Hours bank')}
                        trigger={
                            <button type="button" className={styles.posterLink}>
                                {posterLabel(poster, t)}
                                <Icon name="chevron-right" size={16} />
                            </button>
                        }
                    >
                        {!wide && <BankLedger clientId={clientId} billing={billing} ledger={ledger} locale={locale} onReopen={onReopen} />}
                    </BottomSheet>
                    {note !== null && <p className={styles.posterNote}>{note}</p>}
                </div>
            )}
            <div className={styles.phoneRows}>
                <ReportRow clientId={clientId} billing={billing} rows={ledger.rows} locale={locale} />
                {invoice === undefined ? (
                    <p className={styles.empty}>{t('No invoices from Infakt yet.')}</p>
                ) : (
                    <BottomSheet
                        open={invoicesOpen && !wide}
                        onOpenChange={setInvoicesOpen}
                        title={t('Invoices')}
                        trigger={
                            <ListRow
                                title={invoiceTitle(invoice, locale, t)}
                                sub={<InvoiceSub invoice={invoice} locale={locale} />}
                                value={moneyOf(invoice.net, invoice.currency, locale, true)}
                                aria-haspopup="dialog"
                            />
                        }
                    >
                        {!wide && <PhoneInvoiceRows clientId={clientId} invoices={billing.invoices} basis="net" locale={locale} />}
                    </BottomSheet>
                )}
            </div>
        </div>
    );
}

/** The report waiting to go out leads with the late dot and its action; otherwise the newest report, a row that opens it. */
function ReportRow({ clientId, billing, rows, locale }: { clientId: number; billing: RetainerBilling; rows: LedgerRow[]; locale: string }) {
    const { t } = useTranslation();
    const waiting = billing.waiting_report;

    if (waiting !== null) {
        return (
            <ListRowStatic
                leading={<LateDotIcon />}
                title={t('Report for {{month}}', { month: monthName(waiting.period, locale) })}
                sub={<span className={styles.accent}>{t('is waiting to be sent')}</span>}
                trailing={
                    <SoloButtonLink variant="outline" size="touch" href={clientReports.edit([clientId, waiting.report_id]).url}>
                        {waiting.status === 'draft' ? t('Draft · send') : t('Open')}
                    </SoloButtonLink>
                }
            />
        );
    }

    const latest = rows.find((row) => row.report_id !== null && row.status !== null);
    if (latest === undefined || latest.report_id === null) {
        return null;
    }

    return (
        <ListRow
            href={clientReports.edit([clientId, latest.report_id]).url}
            title={t('Report for {{month}}', { month: monthName(latest.period, locale) })}
            sub={ledgerStates({ ...latest, settlement: null }, locale, t).report ?? undefined}
        />
    );
}

/** Figma 334:3502: the retainer terms, the months with their settlement and report lines, the entry row, Starsze. */
function BankLedger({
    clientId,
    billing,
    ledger,
    locale,
    onReopen,
}: {
    clientId: number;
    billing: RetainerBilling;
    ledger: BillingLedger;
    locale: string;
    onReopen: () => void;
}) {
    const { t } = useTranslation();
    const { rows, start, next } = ledger;
    const several = billing.retainer.active.length > 1;
    const accented = rows.find((row) => ledgerValue(row, locale) !== '·');

    return (
        <>
            {billing.retainer.active.length > 0 && (
                <dl className={`${styles.list} ${styles.terms}`}>
                    {billing.retainer.active.map((position) => (
                        <KvRow key={position.id} layout="split" label={positionSummary(position, several, locale, t)}>
                            {position.monthly_fee !== null
                                ? t('{{money}} net / mo', { money: moneyOf(position.monthly_fee, position.currency, locale, false) })
                                : '·'}
                        </KvRow>
                    ))}
                </dl>
            )}
            <ul className={styles.list} aria-label={t('Hours bank')}>
                {rows.map((row, index) => {
                    const older = rows[index + 1];
                    const correction = older !== undefined && Math.abs(row.opening - older.closing) >= 0.005 ? row.opening - older.closing : null;
                    const states = ledgerStates(row, locale, t);

                    return (
                        <Fragment key={row.key}>
                            <li className={styles.monthRow}>
                                <span className={styles.monthText}>
                                    <span className={styles.monthTitle}>{periodLabel(row, rows[index - 1], locale)}</span>
                                    <span className={styles.monthSub}>{ledgerSub(row, locale, t)}</span>
                                </span>
                                <span className={styles.monthBalance}>
                                    <span className={styles.monthValue} data-tone={row === accented && row.closing < 0 ? 'accent' : undefined}>
                                        {ledgerValue(row, locale)}
                                    </span>
                                    {states.settlement !== null && <span className={styles.monthState}>{states.settlement}</span>}
                                    {states.report !== null && row.report_id !== null && (
                                        <Link
                                            href={clientReports.edit([clientId, row.report_id]).url}
                                            className={`${styles.monthState} ${styles.reportLink} ${touchTargetClass}`}
                                        >
                                            {states.report}
                                        </Link>
                                    )}
                                    {row.closure_id !== null && (
                                        <SoloButton variant="text" size="touch" className={styles.monthAction} onClick={onReopen}>
                                            {t('Reopen the month')}
                                        </SoloButton>
                                    )}
                                </span>
                            </li>
                            {correction !== null && (
                                <li className={styles.splitRow}>
                                    <span>{t('Correction {{date}}', { date: shortDate(row.period_start, locale) })}</span>
                                    <span>{h(row.opening, locale, true)}</span>
                                </li>
                            )}
                        </Fragment>
                    );
                })}
                {start !== null && (
                    <li className={styles.splitRow}>
                        <span>
                            {t('Start {{date}}', { date: shortDate(start.date, locale) })} · {t('ledger_start')}
                        </span>
                        <span>{h(start.balance, locale, true)}</span>
                    </li>
                )}
            </ul>
            <p className={styles.sheetNote}>
                {billing.cap !== null
                    ? t('The balance is what carries into the next month. Rollover up to {{cap}} h.', { cap: hoursTerms(billing.cap, locale) })
                    : t('The balance is what carries into the next month.')}
            </p>
            {next !== null && (
                <BodyLink
                    className={styles.sheetMore}
                    href={clients.show({ client: clientId, tab: 'rozliczenia' }).url}
                    data={{ miesiace: next }}
                    only={['billing_ledger']}
                    preserveUrl
                    preserveScroll
                    preserveState
                >
                    {t('Older')} →
                </BodyLink>
            )}
        </>
    );
}

function InvoiceSub({ invoice, locale, order = false }: { invoice: BillingInvoice; locale: string; order?: boolean }) {
    const { t } = useTranslation();
    const line = order ? orderInvoiceLine(invoice, locale, t) : { text: invoiceState(invoice, locale, t), accent: invoice.overdue };

    return (
        <span className={styles.tone} data-tone={line.accent ? 'accent' : undefined}>
            {line.text}
        </span>
    );
}

/** Figma 334:3611 (Faktury sheet) and 335:3956 (an order's list): one row per invoice, opening it in Infakt. */
export function PhoneInvoiceRows({
    clientId,
    invoices,
    basis,
    locale,
    order = false,
}: {
    clientId: number;
    invoices: ClientBilling['invoices'];
    basis: 'net' | 'gross';
    locale: string;
    /** An order's list says what each invoice waits for (335:3956). */
    order?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            {invoices.rows.length === 0 ? (
                <p className={styles.empty}>{t('No invoices from Infakt yet.')}</p>
            ) : (
                <ul className={styles.list}>
                    {invoices.rows.map((invoice) => {
                        const content = {
                            title: invoiceTitle(invoice, locale, t),
                            sub: <InvoiceSub invoice={invoice} locale={locale} order={order} />,
                            value: moneyOf(invoice[basis], invoice.currency, locale, true),
                        };

                        return (
                            <li key={invoice.id}>
                                {invoice.external_url !== null ? (
                                    <ListRow {...content} href={invoice.external_url} external />
                                ) : (
                                    <ListRowStatic {...content} />
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {invoices.more > 0 && (
                <BodyLink className={styles.sheetMore} href={`${clients.edit(clientId, { query: { invoice_year: 'all' } }).url}#invoices`}>
                    {t('{{count}} more', { count: invoices.more })} →
                </BodyLink>
            )}
        </>
    );
}

/** An order's invoices on phones (Figma 335:3956): FAKTURY · N over the rows, gross as the order is agreed. */
export function PhoneInvoicesSection({
    clientId,
    invoices,
    basis,
    locale,
}: {
    clientId: number;
    invoices: ClientBilling['invoices'];
    basis: 'net' | 'gross';
    locale: string;
}) {
    const { t } = useTranslation();

    return (
        <section className={styles.phone}>
            <SectionHead kind="body" label={`${t('Invoices')} · ${invoices.rows.length + invoices.more}`} />
            <PhoneInvoiceRows clientId={clientId} invoices={invoices} basis={basis} locale={locale} order />
        </section>
    );
}
