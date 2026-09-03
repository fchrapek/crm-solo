import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { TableContainer } from '@/components/table-container';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePageActions } from '@/contexts/page-context';
import { type Currency, useFormatters } from '@/lib/format';
import clients from '@/routes/clients';
import revenue from '@/routes/revenue';
import { BreadcrumbItem, SharedData } from '@/types';

import styles from './index.module.css';

type Metric = 'net' | 'gross';

interface RevenueClientRow {
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

interface TrendPoint {
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

interface FxInfo {
    base: string;
    rates: Record<string, number>;
}

interface RevenuePageProps extends SharedData {
    filters: Filters;
    summary: Summary;
    clients: RevenueClientRow[];
    trend: TrendPoint[];
    fx: FxInfo;
}

const PERIOD_KEYS: Record<string, string> = {
    this_month: 'This month',
    this_quarter: 'This quarter',
    ytd: 'Year to date',
    last_12_months: 'Last 12 months',
};

// Monochrome ramp (dark → light slate) so bars stay distinguishable without colour.
const CHART_COLORS = ['#1f2937', '#374151', '#4b5563', '#6b7280', '#8b919b', '#a1a7b0', '#c2c7ce', '#d8dce1'];

export default function Index() {
    const { t } = useTranslation();
    const { setBreadcrumbs } = usePageActions();
    const { money } = useFormatters();
    const { filters, summary, clients: clientRows, trend, fx } = usePage<RevenuePageProps>().props;

    const [metric, setMetric] = React.useState<Metric>('net');

    const breadcrumbs: BreadcrumbItem[] = React.useMemo(() => [{ title: 'Finances', href: revenue.index().url }], []);
    useEffect(() => {
        setBreadcrumbs(breadcrumbs);
    }, [breadcrumbs, setBreadcrumbs]);

    const currency = summary.currency as Currency;

    function applyFilter(patch: Partial<Filters>) {
        router.get(
            window.location.pathname,
            { period: filters.period, basis: filters.basis, ...patch },
            { replace: true, preserveState: true, preserveScroll: true },
        );
    }

    const fxNote = Object.entries(fx.rates)
        .map(([cur, rate]) => t('{{cur}} converted at {{rate}}', { cur, rate }))
        .join(' · ');

    const total = metric === 'net' ? summary.total_net : summary.total_gross;
    const avg = metric === 'net' ? summary.avg_per_client_net : summary.avg_per_client_gross;
    const top3 = metric === 'net' ? summary.top3_share_net : summary.top3_share_gross;
    const totalTrend = metric === 'net' ? summary.trend_net : summary.trend_gross;

    const chartData = clientRows.slice(0, 8).map((c, i) => ({
        name: c.name.length > 22 ? `${c.name.slice(0, 21)}…` : c.name,
        value: metric === 'net' ? c.net : c.gross,
        fill: CHART_COLORS[i % CHART_COLORS.length],
    }));

    return (
        <>
            <Head title={t('Finances')} />

            <div className="pageContainer">
                <div className={styles.controls}>
                    <Select value={filters.period} onValueChange={(v) => applyFilter({ period: v })}>
                        <SelectTrigger className={styles.control}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(PERIOD_KEYS).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {t(label)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className={styles.toggleGroup} role="group">
                        <button type="button" data-active={filters.basis === 'accrual'} onClick={() => applyFilter({ basis: 'accrual' })}>
                            {t('Invoiced')}
                        </button>
                        <button type="button" data-active={filters.basis === 'cash'} onClick={() => applyFilter({ basis: 'cash' })}>
                            {t('Paid')}
                        </button>
                    </div>

                    <div className={styles.toggleGroup} role="group">
                        <button type="button" data-active={metric === 'net'} onClick={() => setMetric('net')}>
                            {t('Net')}
                        </button>
                        <button type="button" data-active={metric === 'gross'} onClick={() => setMetric('gross')}>
                            {t('Gross')}
                        </button>
                    </div>

                    {fxNote && <span className={styles.fxNote}>{fxNote}</span>}
                </div>

                <div className={styles.kpiGrid}>
                    <KpiCard label={t('Total revenue')} value={money(total, currency)} trend={totalTrend} />
                    <KpiCard label={t('Invoices')} value={String(summary.invoice_count)} />
                    <KpiCard label={t('Clients')} value={String(summary.client_count)} />
                    <KpiCard label={t('Avg per client')} value={money(avg, currency)} />
                    <KpiCard label={t('Top 3 concentration')} value={`${top3}%`} />
                </div>

                <div className={styles.chartGrid}>
                    <div className={styles.chartCard}>
                        <p className={styles.chartTitle}>{t('Revenue by client')}</p>
                        <ResponsiveContainer width="100%" height={260}>
                            <BarChart data={chartData} layout="vertical" margin={{ left: 8, right: 16 }}>
                                <CartesianGrid horizontal={false} stroke="var(--color-border)" />
                                <XAxis type="number" tickFormatter={(v) => money(v, currency)} fontSize={11} stroke="var(--color-muted-foreground)" />
                                <YAxis type="category" dataKey="name" width={140} fontSize={11} stroke="var(--color-muted-foreground)" />
                                <Tooltip formatter={(v: number) => money(v, currency)} />
                                <Bar dataKey="value" radius={[0, 4, 4, 0]}>
                                    {chartData.map((entry, i) => (
                                        <Cell key={i} fill={entry.fill} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>

                    <div className={styles.chartCard}>
                        <p className={styles.chartTitle}>{t('Monthly trend')}</p>
                        <ResponsiveContainer width="100%" height={260}>
                            <LineChart data={trend} margin={{ left: 8, right: 16 }}>
                                <CartesianGrid stroke="var(--color-border)" />
                                <XAxis dataKey="month" fontSize={11} stroke="var(--color-muted-foreground)" />
                                <YAxis tickFormatter={(v) => money(v, currency)} fontSize={11} width={80} stroke="var(--color-muted-foreground)" />
                                <Tooltip formatter={(v: number) => money(v, currency)} />
                                <Line type="monotone" dataKey={metric} stroke="var(--color-foreground)" strokeWidth={2} dot={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                <TableContainer>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('Client')}</TableHead>
                            <TableHead className={styles.numCol}>{t('Revenue')}</TableHead>
                            <TableHead className={styles.numCol}>{t('Share')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {clientRows.map((c) => {
                            const value = metric === 'net' ? c.net : c.gross;
                            const share = metric === 'net' ? c.share_net : c.share_gross;
                            return (
                                <TableRow
                                    key={c.client_key}
                                    className={c.client_id ? styles.clickableRow : undefined}
                                    onClick={() => c.client_id && router.visit(clients.edit(c.client_id).url)}
                                >
                                    <TableCell>{c.name}</TableCell>
                                    <TableCell className={styles.numCol}>{money(value, currency)}</TableCell>
                                    <TableCell className={styles.numCol}>{share}%</TableCell>
                                </TableRow>
                            );
                        })}
                        {clientRows.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={3} className="emptyCell">
                                    {t('No revenue in this period.')}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </TableContainer>
            </div>
        </>
    );
}

function KpiCard({ label, value, trend }: { label: string; value: string; trend?: number | null }) {
    return (
        <div className={styles.kpiCard}>
            <p className={styles.kpiLabel}>{label}</p>
            <p className={styles.kpiValue}>{value}</p>
            {trend !== undefined && (
                <div className={styles.kpiTrend}>
                    <TrendBadge value={trend} />
                </div>
            )}
        </div>
    );
}

function TrendBadge({ value }: { value: number | null | undefined }) {
    const { t } = useTranslation();
    if (value === null || value === undefined) {
        return (
            <span className={styles.trendNeutral} title={t('No comparable prior period')}>
                <Minus size={13} />
            </span>
        );
    }
    const up = value >= 0;
    return (
        <span className={up ? styles.trendUp : styles.trendDown}>
            {up ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
            {Math.abs(value)}%
        </span>
    );
}
