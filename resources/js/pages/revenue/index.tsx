import { KvRow } from '@/components/solo/kv-row';
import { ListTable, ListTableCell, ListTableHead, ListTableRow } from '@/components/solo/list-table';
import { Stat } from '@/components/solo/primitives';
import { RecordFrame } from '@/components/solo/record-frame';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceLinkPill, type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileGrid, TileHeader, TileInset, TileNote } from '@/components/solo/tile';
import { capitalize, monthGenitive, monthMark, monthName } from '@/lib/dates';
import { localeOf } from '@/lib/hours-text';
import clients from '@/routes/clients';
import revenue from '@/routes/revenue';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { moneyOf } from '../clients/components/client-billing/format';
import styles from './index.module.css';

export type Metric = 'net' | 'gross';

export interface RevenueClientRow {
    client_key: string | number;
    client_id: number | null;
    name: string;
    invoice_count: number;
    net: number;
    gross: number;
    last_date: string | null;
    share_net: number;
    share_gross: number;
    trend_net: number | null;
    trend_gross: number | null;
}

interface Summary {
    total_net: number;
    total_gross: number;
    trend_net: number | null;
    trend_gross: number | null;
    invoice_count: number;
    client_count: number;
    avg_per_client_net: number;
    avg_per_client_gross: number;
    top3_share_net: number;
    top3_share_gross: number;
    currency: string;
    basis: string;
}

export interface TrendPoint {
    month: string;
    net: number;
    gross: number;
}

interface Filters {
    period: string;
    basis: string;
    from: string;
    to: string;
}

interface RevenuePageProps extends SharedData {
    filters: Filters;
    summary: Summary;
    clients: RevenueClientRow[];
    trend: TrendPoint[];
    fx: { base: string; rates: Record<string, number> };
}

export const PERIODS = ['this_month', 'this_quarter', 'ytd', 'last_12_months'] as const;

const PERIOD_LABEL: Record<string, string> = {
    this_month: 'This month',
    this_quarter: 'This quarter',
    ytd: 'Year to date',
    last_12_months: 'Last 12 months',
    custom: 'Custom period',
};

const KEEP = { preserveState: true, preserveScroll: true, replace: true };

/** Net unless the URL asks for gross (`?kwoty=brutto`): the server sends both, so the choice is the page's own. */
export function metricOf(url: string): Metric {
    return new URLSearchParams(url.split('?')[1] ?? '').get('kwoty') === 'brutto' ? 'gross' : 'net';
}

export function revenueUrl(filters: Pick<Filters, 'period' | 'basis'>, metric: Metric): string {
    return revenue.index({
        query: { period: filters.period, ...(filters.basis === 'cash' ? { basis: 'cash' } : {}), ...(metric === 'gross' ? { kwoty: 'brutto' } : {}) },
    }).url;
}

/** "+12 %", "−10 %", or null when there is no earlier period to compare with. */
export function trendText(value: number | null, locale: string): string | null {
    if (value === null) {
        return null;
    }
    const rounded = Math.round(value);

    return `${rounded > 0 ? '+' : rounded < 0 ? '−' : ''}${Math.abs(rounded).toLocaleString(locale)}\u00a0%`;
}

/** Each bar's height as a share of the tallest month. */
export function trendBars(trend: TrendPoint[], metric: Metric): { month: string; value: number; share: number }[] {
    const top = Math.max(0, ...trend.map((point) => point[metric]));

    return trend.map((point) => ({ month: point.month, value: point[metric], share: top > 0 ? point[metric] / top : 0 }));
}

/** Figma: Przychody (1095:48739, phone 1095:49147). Invoiced or paid revenue of a period from the Infakt mirror: the total, its concentration, the months and the clients. */
export default function Index() {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const page = usePage<RevenuePageProps>();
    const { filters, summary, clients: rows, trend, fx } = page.props;
    const metric = metricOf(page.url);
    const currency = summary.currency;
    const money = (value: number, cents = true) => moneyOf(value, currency, locale, cents);
    const percent = (value: number) => `${value.toLocaleString(locale)}\u00a0%`;

    const total = summary[`total_${metric}`];
    const other = metric === 'net' ? summary.total_gross : summary.total_net;
    const top3 = summary[`top3_share_${metric}`];
    const top = rows.slice(0, 3);
    const trendLine = trendText(summary[`trend_${metric}`], locale);
    const bars = trendBars(trend, metric);
    const peak = bars.reduce<(typeof bars)[number] | null>((best, bar) => (best === null || bar.value > best.value ? bar : best), null);
    const running = new Date().toISOString().slice(0, 7);
    const fxNote = Object.entries(fx.rates).map(([cur, rate]) => t('{{cur}} converted at {{rate}}', { cur, rate: rate.toLocaleString(locale) }));
    const range = `${capitalize(monthName(filters.from.slice(0, 7), locale))} ${filters.from.slice(0, 4)} ${t('to')} ${monthGenitive(filters.to.slice(0, 7), locale)} ${filters.to.slice(0, 4)}`;
    const basisPill = (basis: string, label: string): PlaceLinkPill => ({
        label,
        href: revenueUrl({ ...filters, basis }, metric),
        active: filters.basis === basis,
        visit: KEEP,
    });
    const metricPill = (value: Metric, label: string): PlaceLinkPill => ({
        label,
        href: revenueUrl(filters, value),
        active: metric === value,
        visit: KEEP,
    });

    return (
        <SoloSurface day="paper">
            <Head title={t('Revenue')} />
            <main className={styles.page}>
                <RecordFrame
                    title={t('Revenue')}
                    noteKind="line"
                    note={[
                        range,
                        t(filters.basis === 'cash' ? 'revenue_note_paid' : 'revenue_note_invoiced'),
                        t(metric).toLocaleLowerCase(locale),
                        ...fxNote,
                    ].join(' · ')}
                />
                <div className={styles.filters}>
                    <div className={styles.filter}>
                        <span className={styles.caption}>{t('revenue_basis')}</span>
                        <Pills label={t('revenue_basis')} items={[basisPill('accrual', t('Invoiced')), basisPill('cash', t('Paid'))]} />
                    </div>
                    <div className={styles.filter}>
                        <span className={styles.caption}>{t('revenue_amounts')}</span>
                        <Pills label={t('revenue_amounts')} items={[metricPill('net', t('Net')), metricPill('gross', t('Gross'))]} />
                    </div>
                </div>
                <TileGrid>
                    <Tile kind="plain">
                        <Stat
                            kind="tile"
                            label={t('Total revenue')}
                            value={money(Math.round(total), false)}
                            sub={[
                                t(metric).toLocaleLowerCase(locale),
                                trendLine !== null ? t('revenue_trend_prior', { change: trendLine }) : t('No comparable prior period'),
                            ].join(' · ')}
                        />
                    </Tile>
                    <Tile kind="plain">
                        <Stat
                            kind="tile"
                            label={t('Top 3 concentration')}
                            value={percent(top3)}
                            sub={top.length > 0 ? top.map((row) => row.name).join(', ') : undefined}
                        />
                        {top.length > 0 && (
                            <div className={styles.split} data-fill="" aria-hidden="true">
                                {top.map((row) => (
                                    <span key={row.client_key} className={styles.splitPart} style={{ flexGrow: row[`share_${metric}`] }} />
                                ))}
                                <span style={{ flexGrow: Math.max(0, 100 - top3) }} />
                            </div>
                        )}
                    </Tile>
                </TileGrid>
                <TileGrid>
                    <Tile kind="plain">
                        <TileHeader
                            label={t('Monthly trend')}
                            end={
                                peak !== null && peak.value > 0 ? (
                                    <span className={styles.caption}>
                                        {t('revenue_trend_peak', {
                                            month: monthName(peak.month, locale),
                                            value: money(Math.round(peak.value), false),
                                        })}
                                    </span>
                                ) : undefined
                            }
                        />
                        {bars.length === 0 ? (
                            <TileNote>{t('No revenue in this period.')}</TileNote>
                        ) : (
                            <ol className={styles.chart} data-fill="" aria-label={t('Monthly trend')}>
                                {bars.map((bar) => (
                                    <li key={bar.month} className={styles.bar} data-running={bar.month === running ? '' : undefined}>
                                        <span className={styles.barTrack}>
                                            <span className={styles.barFill} style={{ height: `${Math.max(bar.share * 100, 1)}%` }} title={money(bar.value)}>
                                                <span className={styles.barValue}>{(bar.value / 1000).toLocaleString(locale, { maximumFractionDigits: 1 })}</span>
                                            </span>
                                        </span>
                                        <span className={styles.barLabel}>{monthMark(bar.month, locale).toLocaleLowerCase(locale)}</span>
                                    </li>
                                ))}
                            </ol>
                        )}
                        {bars.length > 0 && (
                            <TileNote>
                                {t('revenue_chart_note', { currency })}
                                {bars.some((bar) => bar.month === running) &&
                                    ` ${t('revenue_chart_running', { month: capitalize(monthName(running, locale)) })}`}
                            </TileNote>
                        )}
                    </Tile>
                    <Tile kind="plain">
                        <TileHeader label={t('revenue_period_figures')} />
                        <dl className={styles.facts}>
                            <KvRow layout="tile" label={t('Invoices')}>
                                {summary.invoice_count}
                            </KvRow>
                            <KvRow layout="tile" label={t('Clients')}>
                                {summary.client_count}
                            </KvRow>
                            <KvRow layout="tile" label={t('Avg per client')}>
                                {money(summary[`avg_per_client_${metric}`])}
                            </KvRow>
                            <KvRow layout="tile" label={metric === 'net' ? t('Gross') : t('Net')}>
                                {money(other)}
                            </KvRow>
                        </dl>
                        <TileNote>{t('revenue_source_note')}</TileNote>
                    </Tile>
                </TileGrid>
                <Tile kind="list">
                    <TileHeader label={t('Revenue by client')} count={rows.length > 0 ? rows.length : undefined} />
                    {rows.length === 0 ? (
                        <TileInset>
                            <TileNote>{t('No revenue in this period.')}</TileNote>
                        </TileInset>
                    ) : (
                        <TileInset className={styles.tableInset}>
                            <ListTable label={t('Revenue by client')}>
                                <thead>
                                    <tr>
                                        <ListTableHead>{t('The client')}</ListTableHead>
                                        <ListTableHead align="end" wide>
                                            {t('Share')}
                                        </ListTableHead>
                                        <ListTableHead align="end" wide>
                                            {t('Invoices')}
                                        </ListTableHead>
                                        <ListTableHead align="end">{t('Revenue')}</ListTableHead>
                                        <ListTableHead align="end" narrow>
                                            {t('Share')}
                                        </ListTableHead>
                                        <ListTableHead wide>{t('revenue_change')}</ListTableHead>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => {
                                        const change = trendText(row[`trend_${metric}`], locale);

                                        return (
                                            <ListTableRow key={row.client_key}>
                                                <ListTableCell kind="title">
                                                    {row.client_id !== null ? (
                                                        <Link
                                                            href={clients.show({ client: row.client_id, tab: 'rozliczenia' }).url}
                                                            className={styles.plainLink}
                                                        >
                                                            {row.name}
                                                        </Link>
                                                    ) : (
                                                        row.name
                                                    )}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" wide>
                                                    {percent(row[`share_${metric}`])}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" wide>
                                                    {row.invoice_count}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end">
                                                    {money(row[metric])}
                                                </ListTableCell>
                                                <ListTableCell kind="number" align="end" narrow tone="muted">
                                                    {percent(row[`share_${metric}`])}
                                                </ListTableCell>
                                                <ListTableCell kind="meta" wide>
                                                    {change ?? t('revenue_trend_new')}
                                                </ListTableCell>
                                            </ListTableRow>
                                        );
                                    })}
                                </tbody>
                            </ListTable>
                        </TileInset>
                    )}
                </Tile>
            </main>
        </SoloSurface>
    );
}

/** The periods as pills; each keeps the basis and the amounts the page is on. */
export function periodPills(filters: Pick<Filters, 'period' | 'basis'>, metric: Metric, t: (key: string) => string): PlaceLinkPill[] {
    return PERIODS.map((period) => ({
        label: t(PERIOD_LABEL[period]),
        href: revenueUrl({ ...filters, period }, metric),
        active: filters.period === period,
    }));
}

Index.place = (({ filters }, { t, url }) => ({
    tokens: [{ label: t('Revenue'), href: revenue.index().url }, { label: t(PERIOD_LABEL[filters.period] ?? 'Custom period') }],
    pills: periodPills(filters, metricOf(url), t),
})) satisfies PlaceResolver<RevenuePageProps>;
