import { Link } from '@inertiajs/react';
import { ArrowRight, Clock, ExternalLink, FileText, FolderKanban, ReceiptText } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { type Currency, useFormatters } from '@/lib/format';

import styles from './overview-tab.module.css';

interface OverviewInvoice {
    id: number;
    number: string | null;
    status: string | null;
    currency: string;
    gross: number;
    net: number;
    invoice_date: string | null;
    /** Deep link into the Infakt web app; null when the row has no external id. */
    external_url: string | null;
}

export interface OverviewData {
    month_hours: number;
    contracted_hours: number;
    carried_hours: number;
    available_hours: number;
    invoices: {
        recent: OverviewInvoice[];
        net_last_12_months: number;
        currency: string;
    };
    latest_report: {
        id: number;
        period_type: string;
        period_start: string | null;
        period_end: string | null;
        status: string;
    } | null;
}

interface OverviewLifecycleEvent {
    id: number;
    from_stage: string | null;
    to_stage: string;
    note: string | null;
    created_at: string;
}

interface OverviewProject {
    id: number;
    name: string;
    tasks_count: number;
    completed_tasks_count: number;
}

interface Props {
    clientId: number;
    overview: OverviewData;
    lifecycleEvents: OverviewLifecycleEvent[];
    projects: OverviewProject[];
}

/**
 * The Przegląd glance - answers "where does this client stand" without
 * opening a working tab: load vs retainer, newest report state, latest
 * activity, project shape. Everything links into its working tab (the Tabs
 * component reads the URL hash, so plain anchors switch tabs).
 */
export function OverviewTab({ clientId, overview, lifecycleEvents, projects }: Props) {
    const { t } = useTranslation();
    const { money } = useFormatters();

    const reportStatusLabel: Record<string, string> = {
        draft: t('Draft'),
        finalized: t('Finalized'),
        sent: t('Sent'),
    };
    // Only `status` is trusted for payment state: Infakt marks documents paid
    // without settling paid_price / left_to_pay, so those columns would read as
    // outstanding on almost every row.
    // Invoice-specific keys, not the report ones: "faktura" is feminine in
    // Polish, so reusing 'Sent'/'Draft' (written for "raport") renders the
    // wrong gender.
    const invoiceStatusLabel: Record<string, string> = {
        paid: t('invoice_status_paid'),
        sent: t('invoice_status_sent'),
        printed: t('invoice_status_printed'),
        draft: t('invoice_status_draft'),
    };
    const invoices = overview.invoices ?? { recent: [], net_last_12_months: 0, currency: 'PLN' };
    const latest = overview.latest_report;
    // Budget is the pool plus anything carried in, so a client sitting on a
    // surplus is not flagged over until they pass the real ceiling.
    const availableHours = overview.available_hours ?? overview.contracted_hours;
    const carriedHours = overview.carried_hours ?? 0;
    const overBudget = availableHours > 0 && overview.month_hours > availableHours;

    return (
        <div className={styles.grid}>
            <section className={styles.card}>
                <div className={styles.cardHeader}>
                    <h3 className={styles.cardTitle}>
                        <Clock size={14} /> {t('Time this month')}
                    </h3>
                    <a className={styles.cardAction} href="#activity">
                        {t('Log time')} <ArrowRight size={12} />
                    </a>
                </div>
                <p className={styles.bigStat}>
                    <span className={overBudget ? styles.statOver : styles.statMain}>{overview.month_hours}</span>
                    {availableHours > 0 && <span className={styles.statLimit}> / {availableHours} h</span>}
                    {availableHours === 0 && <span className={styles.statLimit}> h</span>}
                    {carriedHours !== 0 && (
                        <span className={styles.statCarried}>
                            ({overview.contracted_hours} {carriedHours > 0 ? '+' : '−'} {Math.abs(carriedHours)}{' '}
                            {carriedHours > 0 ? t('carried over') : t('overrun')})
                        </span>
                    )}
                </p>
                {availableHours > 0 && (
                    <p className={styles.cardHint}>{overBudget ? t('Over the retainer limit') : t('Within the retainer limit')}</p>
                )}
            </section>

            <section className={styles.card}>
                <div className={styles.cardHeader}>
                    <h3 className={styles.cardTitle}>
                        <FileText size={14} /> {t('Latest report')}
                    </h3>
                    <a className={styles.cardAction} href="#reports">
                        {t('Reports')} <ArrowRight size={12} />
                    </a>
                </div>
                {latest ? (
                    <>
                        <p className={styles.bigStatSmall}>
                            <Link className={styles.reportLink} href={`/clients/${clientId}/reports/${latest.id}`} prefetch>
                                {latest.period_start} - {latest.period_end}
                            </Link>
                        </p>
                        <p className={styles.cardHint}>{reportStatusLabel[latest.status] ?? latest.status}</p>
                    </>
                ) : (
                    <p className={styles.cardHint}>{t('No reports yet.')}</p>
                )}
            </section>

            <section className={`${styles.card} ${styles.cardWide}`}>
                <div className={styles.cardHeader}>
                    <h3 className={styles.cardTitle}>
                        <ReceiptText size={14} /> {t('Invoices')}
                    </h3>
                    {invoices.net_last_12_months > 0 && (
                        <span className={styles.cardMeta}>
                            {t('Last 12 months')}: {money(invoices.net_last_12_months, invoices.currency as Currency)} {t('net')}
                        </span>
                    )}
                    <a className={styles.cardAction} href="#invoices">
                        {t('Invoices')} <ArrowRight size={12} />
                    </a>
                </div>
                {invoices.recent.length === 0 ? (
                    <p className={styles.cardHint}>{t('No invoices yet.')}</p>
                ) : (
                    <ul className={styles.invoiceList}>
                        {invoices.recent.map((invoice) => (
                            <li key={invoice.id} className={styles.invoiceRow}>
                                <span className={styles.invoiceDate}>
                                    {invoice.invoice_date ? new Date(invoice.invoice_date).toLocaleDateString() : '-'}
                                </span>
                                <span className={styles.invoiceNumber}>{invoice.number ?? `#${invoice.id}`}</span>
                                <span className={styles.invoiceAmount}>{money(invoice.gross, invoice.currency as Currency)}</span>
                                {invoice.status && (
                                    <span className={styles.invoiceStatus} data-paid={invoice.status === 'paid' ? 'true' : 'false'}>
                                        {invoiceStatusLabel[invoice.status] ?? invoice.status}
                                    </span>
                                )}
                                {invoice.external_url && (
                                    <a
                                        className={styles.invoiceLink}
                                        href={invoice.external_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label={t('Open in Infakt')}
                                        title={t('Open in Infakt')}
                                    >
                                        <ExternalLink size={12} />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className={`${styles.card} ${styles.cardWide}`}>
                <div className={styles.cardHeader}>
                    <h3 className={styles.cardTitle}>{t('Recent activity')}</h3>
                    <a className={styles.cardAction} href="#activity">
                        {t('Activity')} <ArrowRight size={12} />
                    </a>
                </div>
                {lifecycleEvents.length === 0 ? (
                    <p className={styles.cardHint}>{t('No activity yet.')}</p>
                ) : (
                    <ol className={styles.eventList}>
                        {lifecycleEvents.slice(0, 3).map((event) => (
                            <li key={event.id} className={styles.eventRow}>
                                <span className={styles.eventDate}>{new Date(event.created_at).toLocaleDateString()}</span>
                                <span className={styles.eventText}>
                                    {event.note ?? (event.from_stage ? `${event.from_stage} → ${event.to_stage}` : event.to_stage)}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <section className={`${styles.card} ${styles.cardWide}`}>
                <div className={styles.cardHeader}>
                    <h3 className={styles.cardTitle}>
                        <FolderKanban size={14} /> {t('Projects')}
                    </h3>
                    <a className={styles.cardAction} href="#work">
                        {t('Work')} <ArrowRight size={12} />
                    </a>
                </div>
                {projects.length === 0 ? (
                    <p className={styles.cardHint}>{t('No projects yet.')}</p>
                ) : (
                    <ul className={styles.projectList}>
                        {projects.map((project) => (
                            <li key={project.id} className={styles.projectRow}>
                                <span className={styles.projectName}>{project.name}</span>
                                <span className={styles.projectCount}>
                                    {project.completed_tasks_count}/{project.tasks_count}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
