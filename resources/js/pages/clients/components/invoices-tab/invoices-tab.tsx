import { Icon } from '@/components/ui/icon';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

import { TableContainer } from '@/components/table-container';
import { SectionCard } from '@/components/ui/section-card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type Currency, useFormatters } from '@/lib/format';

import { monthName } from '@/lib/dates';
import styles from './invoices-tab.module.css';

export interface ClientInvoice {
    id: number;
    number: string | null;
    status: string | null;
    currency: string;
    gross: number;
    net: number;
    invoice_date: string | null;
    external_url: string | null;
}

export interface ClientInvoices {
    rows: ClientInvoice[];
    total: number;
    truncated: boolean;
}

export interface InvoiceFilters {
    year: number | 'all' | 'last12';
    month: number | null;
    years: number[];
}

export interface ClientRevenueSummary {
    currency: string;
    period_net: number;
    period_gross: number;
    period_invoice_count: number;
    alltime_net: number;
    alltime_gross: number;
    share_net: number;
    last_invoice_date: string | null;
}

interface Props {
    invoices: ClientInvoices;
    revenue: ClientRevenueSummary | null;
    filters: InvoiceFilters;
}

const MONTHS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

/**
 * Billing for one client. The year/month filter on top governs everything
 * below it - the revenue stat cards AND the invoice table - so the tab always
 * answers one question for one period. A client-scoped view of the same data
 * the Income page aggregates - not a second feed.
 */
export function InvoicesTab({ invoices, revenue, filters }: Props) {
    const { t, i18n } = useTranslation();
    const f = useFormatters();

    // Infakt statuses carry Polish grammatical gender: "faktura" is feminine,
    // so these keys are separate from the report 'Sent'/'Draft' ones.
    const statusLabel: Record<string, string> = {
        paid: t('invoice_status_paid'),
        sent: t('invoice_status_sent'),
        printed: t('invoice_status_printed'),
        draft: t('invoice_status_draft'),
    };

    const yearValue = String(filters.year);
    const monthValue = filters.month === null ? 'all' : String(filters.month);

    // The default year (current) may not appear in the data yet - keep it a
    // valid option so the select always shows its value.
    const yearOptions = typeof filters.year !== 'number' || filters.years.includes(filters.year) ? filters.years : [filters.year, ...filters.years];
    const wholeRange = (year: string) => year === 'all' || year === 'last12';

    const applyFilters = (year: string, month: string) => {
        // Merge with the existing query string - the time-entries pager shares
        // the URL and must survive a filter change.
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        delete params.invoice_month;
        router.get(
            window.location.pathname + window.location.hash,
            {
                ...params,
                invoice_year: year,
                ...(month === 'all' ? {} : { invoice_month: month }),
            },
            { only: ['invoices', 'invoiceFilters', 'revenue'], preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const monthOf = (month: number) => monthName(`2000-${String(month).padStart(2, '0')}`, i18n.language);

    const periodText =
        filters.year === 'all'
            ? t('All years')
            : filters.year === 'last12'
              ? t('Last 12 months')
              : filters.month === null
                ? String(filters.year)
                : `${monthOf(filters.month)} ${filters.year}`;

    return (
        <div className={styles.root}>
            <div className={styles.filterBar}>
                {/* The trigger fills its wrapper (width: 100% by default), so
                    the fixed-width wrappers are what keep the two selects side
                    by side instead of stacking full-width. */}
                <div className={styles.filterSelect}>
                    <Select value={yearValue} onValueChange={(value) => applyFilters(value, wholeRange(value) ? 'all' : monthValue)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All years')}</SelectItem>
                            <SelectItem value="last12">{t('Last 12 months')}</SelectItem>
                            {yearOptions.map((year) => (
                                <SelectItem key={year} value={String(year)}>
                                    {year}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className={styles.filterSelect}>
                    <Select value={monthValue} onValueChange={(value) => applyFilters(yearValue, value)} disabled={wholeRange(yearValue)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{t('All months')}</SelectItem>
                            {MONTHS.map((month) => (
                                <SelectItem key={month} value={String(month)}>
                                    {monthOf(month)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <p className={styles.nettoNote}>{t('Amounts are net unless labeled gross.')}</p>
            </div>

            {revenue && <RevenueStats revenue={revenue} periodText={periodText} />}

            <SectionCard
                title={t('Invoices')}
                actions={
                    invoices.total > 0 ? (
                        <span className={styles.count}>
                            {invoices.truncated
                                ? t('Showing {{shown}} of {{total}}', { shown: invoices.rows.length, total: invoices.total })
                                : t('{{count}} invoices', { count: invoices.total })}
                        </span>
                    ) : undefined
                }
            >
                {invoices.rows.length === 0 ? (
                    <div className={styles.empty}>{t('No invoices in this period.')}</div>
                ) : (
                    <TableContainer>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Date')}</TableHead>
                                <TableHead>{t('Invoice #')}</TableHead>
                                <TableHead className={styles.amount}>{t('net')}</TableHead>
                                <TableHead className={styles.amount}>{t('Gross')}</TableHead>
                                <TableHead>{t('Status')}</TableHead>
                                <TableHead className={styles.linkCell} />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.rows.map((invoice) => (
                                <TableRow key={invoice.id}>
                                    <TableCell className={styles.date}>
                                        {invoice.invoice_date ? new Date(invoice.invoice_date).toLocaleDateString() : '-'}
                                    </TableCell>
                                    <TableCell className={styles.number}>{invoice.number ?? `#${invoice.id}`}</TableCell>
                                    <TableCell className={styles.amount}>{f.money(invoice.net, invoice.currency as Currency)}</TableCell>
                                    <TableCell className={styles.amount}>{f.money(invoice.gross, invoice.currency as Currency)}</TableCell>
                                    <TableCell>
                                        {invoice.status && (
                                            <span className={styles.status} data-paid={invoice.status === 'paid' ? 'true' : 'false'}>
                                                {statusLabel[invoice.status] ?? invoice.status}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className={styles.linkCell}>
                                        {invoice.external_url && (
                                            <a
                                                className={styles.link}
                                                href={invoice.external_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                aria-label={t('Open in Infakt')}
                                                title={t('Open in Infakt')}
                                            >
                                                <Icon name="external-link" size={16} />
                                            </a>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </TableContainer>
                )}
            </SectionCard>
        </div>
    );
}

function RevenueStats({ revenue, periodText }: { revenue: ClientRevenueSummary; periodText: string }) {
    const { t } = useTranslation();
    const f = useFormatters();
    const currency = revenue.currency as Currency;

    return (
        <div className={styles.statsGrid}>
            <div className={styles.statCard}>
                <span className={styles.revenueLabel}>
                    {t('Revenue')} · {periodText}
                </span>
                <span className={styles.revenueValue}>{f.money(revenue.period_net, currency)}</span>
                <span className={styles.revenueSub}>{t('{{gross}} gross', { gross: f.money(revenue.period_gross, currency) })}</span>
            </div>
            <div className={styles.statCard}>
                <span className={styles.revenueLabel}>{t('Share of your revenue')}</span>
                <span className={styles.revenueValue}>{revenue.share_net}%</span>
                <span className={styles.revenueSub}>{t('{{count}} invoices', { count: revenue.period_invoice_count })}</span>
            </div>
            <div className={styles.statCard}>
                <span className={styles.revenueLabel}>{t('All-time')}</span>
                <span className={styles.revenueValue}>{f.money(revenue.alltime_net, currency)}</span>
                {revenue.last_invoice_date && <span className={styles.revenueSub}>{t('Last: {{date}}', { date: revenue.last_invoice_date })}</span>}
            </div>
        </div>
    );
}
