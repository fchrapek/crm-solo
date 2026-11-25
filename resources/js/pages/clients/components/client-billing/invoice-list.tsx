import { Chip } from '@/components/solo/chip';
import { KvRow } from '@/components/solo/kv-row';
import { ListTable, ListTableCell, ListTableHead, ListTableRow } from '@/components/solo/list-table';
import { ResponsiveSelect } from '@/components/solo/responsive-select';
import { Pills } from '@/components/solo/shell/pills';
import type { PlaceLinkPill, PlacePill } from '@/components/solo/shell/place';
import { Tile, TileHeader, TileInset, TileNote } from '@/components/solo/tile';
import { capitalize, inMonth, monthName, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import { router } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import styles from './client-billing.module.css';
import { invoiceChip, invoiceState, moneyOf } from './format';
import type { BillingInvoice, BillingInvoiceList } from './types';

const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

const VISIT = { only: ['billing_invoices'], preserveScroll: true, preserveState: true, replace: true };

type Year = BillingInvoiceList['filters']['year'];

/** The list's address: the tab as it stands (the month shown) with the list's own year and month. */
export function invoiceListUrl(clientId: number, period: string | null, year: Year, month: number | null): string {
    return clients.show(
        { client: clientId, tab: 'billing' },
        {
            query: {
                ...(period !== null ? { month: period } : {}),
                invoice_year: String(year),
                ...(month !== null && typeof year === 'number' ? { invoice_month: String(month) } : {}),
            },
        },
    ).url;
}

/** The period choices: a year (a menu once the client has several), the rolling year and everything. */
export function yearPills(
    filters: BillingInvoiceList['filters'],
    thisYear: number,
    url: (year: Year) => string,
    t: (key: string) => string,
): PlacePill[] {
    const picked = typeof filters.year === 'number' ? filters.year : null;
    const years = [...new Set([...(picked !== null ? [picked] : []), thisYear, ...filters.years])].sort((a, b) => b - a);
    const link = (year: number): PlaceLinkPill => ({ label: String(year), href: url(year), active: year === picked, visit: VISIT });
    const shown = picked ?? years[0];

    return [
        years.length > 1 ? { label: String(shown), active: picked !== null, menu: years.map(link) } : link(shown),
        { label: t('Last 12 months'), href: url('last12'), active: filters.year === 'last12', visit: VISIT },
        { label: t('All years'), href: url('all'), active: filters.year === 'all', visit: VISIT },
    ];
}

export interface ShownMonth {
    period: string;
    invoices: BillingInvoice[];
    drafting: boolean;
}

/** The (i) of Invoices · Infakt: net amounts, and for a month shown without an invoice, that it has none (yet). */
export function invoiceListHint(month: ShownMonth | null, locale: string, t: TFunction): string {
    const net = t('Net amounts.');
    if (month === null || month.invoices.length > 0) {
        return net;
    }
    const name = capitalize(monthName(month.period, locale));

    return `${net} ${month.drafting ? t('month_invoices_none_yet', { month: name }) : t('month_invoices_none', { month: name })}`;
}

/** Figma 10 (1449:18014): the month's own invoices over the list's filters, chosen by the month they book, the rows marked. */
function MonthInvoices({ month, locale }: { month: ShownMonth; locale: string }) {
    const { t } = useTranslation();
    const name = monthName(month.period, locale);
    const label = t('month_invoices_heading', { count: month.invoices.length, month: name, in: inMonth(month.period, t) });

    if (month.invoices.length === 0) {
        return (
            <TileInset>
                <TileNote>{t('month_invoices_missing', { month: name })}</TileNote>
            </TileInset>
        );
    }

    return (
        <>
            <TileInset>
                <TileNote>{label}</TileNote>
            </TileInset>
            <InvoiceTable label={label} rows={month.invoices} locale={locale} column="date" current />
        </>
    );
}

/** The rows of an invoice table: number, state and net, then the issue date or the gross amount; a phone keeps the number, net and state. */
function InvoiceTable({
    label,
    rows,
    locale,
    column,
    current = false,
}: {
    label: string;
    rows: BillingInvoice[];
    locale: string;
    column: 'date' | 'gross';
    current?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <TileInset className={styles.tableInset}>
            <ListTable label={label}>
                <thead>
                    <tr>
                        <ListTableHead>{t('invoice_number')}</ListTableHead>
                        <ListTableHead wide>{t('Status')}</ListTableHead>
                        {column === 'date' && <ListTableHead wide>{t('Date')}</ListTableHead>}
                        <ListTableHead align="end">{t('Net')}</ListTableHead>
                        <ListTableHead narrow>{t('Status')}</ListTableHead>
                        {column === 'gross' && (
                            <ListTableHead align="end" wide>
                                {t('Gross')}
                            </ListTableHead>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((invoice) => {
                        const chip = invoiceChip(invoice, locale, t);
                        const number = invoice.number ?? t('No number');

                        return (
                            <ListTableRow key={invoice.id} current={current}>
                                <ListTableCell kind="title">
                                    {invoice.external_url !== null ? (
                                        <a
                                            href={invoice.external_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            title={t('Open in Infakt')}
                                            className={styles.plainLink}
                                        >
                                            {number}
                                        </a>
                                    ) : (
                                        number
                                    )}
                                </ListTableCell>
                                <ListTableCell wide>
                                    <Chip status={chip.status}>{chip.label}</Chip>
                                </ListTableCell>
                                {column === 'date' && (
                                    <ListTableCell wide>{invoice.invoice_date !== null ? shortDate(invoice.invoice_date, locale) : ''}</ListTableCell>
                                )}
                                <ListTableCell kind="number" align="end">
                                    {moneyOf(invoice.net, invoice.currency, locale, true)}
                                </ListTableCell>
                                <ListTableCell kind="meta" narrow className={styles.nowrap}>
                                    {invoiceState(invoice, locale, t)}
                                </ListTableCell>
                                {column === 'gross' && (
                                    <ListTableCell kind="number" align="end" wide>
                                        {moneyOf(invoice.gross, invoice.currency, locale, true)}
                                    </ListTableCell>
                                )}
                            </ListTableRow>
                        );
                    })}
                </tbody>
            </ListTable>
        </TileInset>
    );
}

/**
 * Figma: Invoices · Infakt. Every invoice of the client from the Infakt mirror for a year, a month of it or the rolling
 * year; the choice travels in the URL and refilters the revenue over the rows with them.
 */
export function InvoiceList({
    clientId,
    period,
    month = null,
    list,
    thisYear,
    locale,
}: {
    clientId: number;
    /** The month Billing shows, kept in the list's links. */
    period: string | null;
    /** The invoices booked in the month shown, whatever the list's filters; drafting when it is the month due for an invoice. */
    month?: ShownMonth | null;
    list: BillingInvoiceList;
    thisYear: number;
    locale: string;
}) {
    const { t } = useTranslation();
    const { filters, revenue, rows } = list;
    const url = (year: Year, month: number | null = null) => invoiceListUrl(clientId, period, year, month);
    const monthOf = (month: number) => capitalize(monthName(`2000-${String(month).padStart(2, '0')}`, locale));
    const periodText =
        filters.year === 'all'
            ? t('All years')
            : filters.year === 'last12'
              ? t('Last 12 months')
              : filters.month === null
                ? String(filters.year)
                : `${monthOf(filters.month)} ${filters.year}`;
    const label = t('Invoices · Infakt');

    return (
        <Tile kind="list" id="faktury">
            <TileHeader
                label={label}
                count={list.total > 0 ? list.total : undefined}
                hint={{ label, text: invoiceListHint(month, locale, t) }}
                end={
                    list.truncated ? (
                        <span className={styles.caption}>{t('Showing {{shown}} of {{total}}', { shown: rows.length, total: list.total })}</span>
                    ) : undefined
                }
            />
            {month !== null && <MonthInvoices month={month} locale={locale} />}
            <TileInset className={styles.filters}>
                <Pills
                    items={yearPills(filters, thisYear, (year) => url(year, year === filters.year ? filters.month : null), t)}
                    label={t('Invoice period')}
                />
                <div className={styles.monthSelect}>
                    <ResponsiveSelect
                        label={t('Month')}
                        value={filters.month === null ? 'all' : String(filters.month)}
                        onValueChange={(value) => router.get(url(filters.year, value === 'all' ? null : Number(value)), {}, VISIT)}
                        options={[
                            { value: 'all', label: t('All months') },
                            ...MONTHS.map((month) => ({ value: String(month), label: monthOf(month) })),
                        ]}
                        disabled={typeof filters.year !== 'number'}
                        control={{ id: 'invoice-month' }}
                    />
                </div>
            </TileInset>
            {revenue !== null && (
                <TileInset>
                    <dl className={styles.revenue}>
                        <KvRow layout="tile" label={`${t('Revenue')} · ${periodText}`}>
                            {moneyOf(revenue.period_net, revenue.currency, locale, true)}
                        </KvRow>
                        <KvRow layout="tile" label={t('Share of your revenue')}>
                            {`${revenue.share_net.toLocaleString(locale)}\u00a0%`}
                        </KvRow>
                        <KvRow layout="tile" label={t('All-time')}>
                            {moneyOf(revenue.alltime_net, revenue.currency, locale, true)}
                        </KvRow>
                        <KvRow layout="tile" label={t('Last invoice')} tone={revenue.last_invoice_date === null ? 'muted' : undefined}>
                            {revenue.last_invoice_date !== null ? shortDate(revenue.last_invoice_date.slice(0, 10), locale) : '·'}
                        </KvRow>
                    </dl>
                </TileInset>
            )}
            {rows.length === 0 ? (
                <TileInset>
                    <TileNote>{list.total === 0 && revenue === null ? t('No invoices from Infakt yet.') : t('No invoices in this period.')}</TileNote>
                </TileInset>
            ) : (
                <InvoiceTable label={label} rows={rows} locale={locale} column="gross" />
            )}
        </Tile>
    );
}
