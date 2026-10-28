import { Head, router, usePage } from '@inertiajs/react';
import { Check, ChevronRight, CircleDashed, ExternalLink, Minus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import FlashMessages from '@/components/flash-messages';
import { Masthead } from '@/components/solo/masthead';
import { Poster } from '@/components/solo/primitives';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import clients from '@/routes/clients';
import monthClose from '@/routes/month-close';
import { MonthCloseType, SharedData } from '@/types';

import { hoursNumber, localeOf } from '../clients/components/client-list/format';
import { nextPeriod } from '../clients/components/client-overview/format';
import { CloseMonthDialog } from '../clients/components/month-settlement';

import { monthName, shortDate } from '@/lib/dates';
import styles from './index.module.css';

type StepState = 'pending' | 'done' | 'skipped';
type CloseType = 'maintenance' | 'gig';

interface RunStep {
    id: number;
    key: string;
    state: StepState;
    note: string | null;
    completed_at: string | null;
}

interface SiteGroup {
    project_id: number;
    name: string;
    steps: RunStep[];
}

interface Run {
    id: number;
    period: string;
    close_type: CloseType;
    status: 'open' | 'completed';
    completed_at: string | null;
    total: number;
    resolved: number;
    sites: SiteGroup[];
    client_steps: RunStep[];
}

/** The financial close of the month, beside the checklist: never a step, never tickable. */
interface SettlementRow {
    state: 'ready' | 'closed' | 'blocked';
    period: string;
    active_period: string;
    closed_on: string | null;
    closing: number | null;
}

interface ClientRow {
    client: { id: number; name: string; type: MonthCloseType; site_count: number };
    template_total: number;
    can_start_checklist: boolean;
    run: Run | null;
    settlement: SettlementRow | null;
}

interface MonthClosePageProps extends SharedData {
    period: string;
    periods: string[];
    clients: ClientRow[];
}

// English strings double as the i18n keys (flat-key convention). Hints are the
// one-line "what this step means" descriptions shown under each label.
const STEP_LABELS: Record<string, string> = {
    // Per site, in working order.
    db_archived: 'DB archived to vault',
    local_db_import: 'Local DB imported',
    wp_updates: 'WordPress updates',
    local_verify: 'Local verify',
    commit_merge: 'Committed and merged',
    live_deploy: 'Pushed and deployed live',
    // Per client.
    reconcile_log: 'Session log reconciled',
    report: 'Report generated',
    summary_email: 'Summary email sent',
    draft_invoice: 'Draft invoice issued',
    // Retired keys, kept so runs closed before the per-site split still render.
    live_check: 'Live page checked',
    db_dump: 'DB dump archived',
    full_site_copy: 'Full site copy',
};

const STEP_HINTS: Record<string, string> = {
    db_archived: 'Production dump filed in the backup vault, integrity checked.',
    local_db_import: 'Dump imported into the local DDEV site, siteurl confirmed.',
    wp_updates: 'Core, plugins, themes and translations, locally on an updates branch.',
    local_verify: 'Key URLs return 200, checksums verify, no fatal.',
    commit_merge: 'Committed per category and fast-forward merged.',
    live_deploy: 'Yours - push, deploy, then verify live.',
    reconcile_log: 'Merge repo session log + git + CRM time into the monthly note.',
    report: 'Generate the client report for projects that need one.',
    summary_email: 'Send a short summary email - tick when it is out.',
    draft_invoice: 'Issue a draft invoice in Infakt for review.',
    live_check: 'Verify the live site is up and looks right.',
    db_dump: 'Dump the production DB into the backup location.',
    full_site_copy: 'Optional - a full-site archive when it is warranted.',
};

/** null: a client with no checklist that still owes this month's hours. */
const GROUPS: { type: CloseType | null; titleKey: string }[] = [
    { type: 'maintenance', titleKey: 'Maintenance sites' },
    { type: 'gig', titleKey: 'Agency gigs' },
    { type: null, titleKey: 'Hours still to close' },
];

function formatPeriod(period: string): string {
    const [year, month] = period.split('-');
    const date = new Date(Number(year), Number(month) - 1, 1);
    return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

export default function Index() {
    const { t } = useTranslation();
    const { period, periods, clients: rows } = usePage<MonthClosePageProps>().props;

    // Cards are collapsed by default; track which are expanded.
    const [expanded, setExpanded] = useState<Set<number>>(new Set());
    function toggleCard(clientId: number) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(clientId)) {
                next.delete(clientId);
            } else {
                next.add(clientId);
            }
            return next;
        });
    }

    function changePeriod(next: string) {
        router.get(monthClose.index().url, { period: next }, { replace: true, preserveState: true, preserveScroll: true });
    }

    function startClose(clientId: number) {
        router.post(monthClose.store().url, { client_id: clientId, period }, { preserveScroll: true });
    }

    function setStepState(step: RunStep, state: StepState) {
        // Clicking the active state again resets it to pending (toggle-off).
        const next: StepState = step.state === state ? 'pending' : state;
        router.patch(monthClose.updateStep(step.id).url, { state: next }, { preserveScroll: true, preserveState: true });
    }

    function renderSteps(steps: RunStep[]) {
        return (
            <ul className={styles.steps}>
                {steps.map((step) => (
                    <li key={step.id} className={styles.step} data-state={step.state}>
                        <button
                            type="button"
                            className={styles.check}
                            aria-pressed={step.state === 'done'}
                            aria-label={t('Mark done')}
                            onClick={() => setStepState(step, 'done')}
                        >
                            {step.state === 'done' ? (
                                <Check size={14} />
                            ) : step.state === 'skipped' ? (
                                <Minus size={14} />
                            ) : (
                                <CircleDashed size={14} />
                            )}
                        </button>

                        <div className={styles.stepBody}>
                            <span className={styles.stepLabel}>{t(STEP_LABELS[step.key] ?? step.key)}</span>
                            <span className={styles.stepHint}>{t(STEP_HINTS[step.key] ?? '')}</span>
                        </div>

                        <button type="button" className={styles.skipBtn} onClick={() => setStepState(step, 'skipped')}>
                            {step.state === 'skipped' ? t('Unskip') : t('Skip')}
                        </button>
                    </li>
                ))}
            </ul>
        );
    }

    function renderCard({ client, run, template_total: templateTotal, can_start_checklist: canStart, settlement }: ClientRow) {
        const resolved = run ? run.resolved : 0;
        const total = run ? run.total : templateTotal;

        const isExpanded = expanded.has(client.id);

        return (
            <section key={client.id} className={styles.card} data-status={run?.status ?? 'not_started'}>
                <header
                    className={styles.cardHeader}
                    data-toggle={run ? 'true' : undefined}
                    onClick={run ? () => toggleCard(client.id) : undefined}
                    role={run ? 'button' : undefined}
                    aria-expanded={run ? isExpanded : undefined}
                >
                    {run && <ChevronRight size={15} className={styles.chevron} data-expanded={isExpanded ? 'true' : 'false'} aria-hidden />}
                    <a
                        href={clients.show({ client: client.id, tab: 'przeglad' }).url}
                        className={styles.clientName}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {client.name}
                        <ExternalLink size={13} />
                    </a>
                    {run ? (
                        <span className={styles.progress} data-status={run.status}>
                            {run.status === 'completed' ? t('Completed') : `${resolved}/${total}`}
                        </span>
                    ) : (
                        canStart && (
                            <button type="button" className={styles.startBtn} onClick={() => startClose(client.id)}>
                                {t('Start close')} · {total}
                            </button>
                        )
                    )}
                </header>

                {run && isExpanded && (
                    <>
                        {run.sites.map((site) => {
                            const siteResolved = site.steps.filter((s) => s.state !== 'pending').length;

                            return (
                                <div key={site.project_id} className={styles.section}>
                                    <h3 className={styles.sectionTitle}>
                                        {site.name}
                                        <span className={styles.sectionCount}>
                                            {siteResolved}/{site.steps.length}
                                        </span>
                                    </h3>
                                    {renderSteps(site.steps)}
                                </div>
                            );
                        })}

                        {run.client_steps.length > 0 && (
                            <div className={styles.section}>
                                {run.sites.length > 0 && <h3 className={styles.sectionTitle}>{t('Client level')}</h3>}
                                {renderSteps(run.client_steps)}
                            </div>
                        )}
                    </>
                )}

                {settlement !== null && <SettlementLine clientId={client.id} settlement={settlement} />}
            </section>
        );
    }

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('Month Close')} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main className={styles.main}>
                    <Poster>{t('Month close')}</Poster>
                    <div className={styles.controls}>
                        <Select value={period} onValueChange={changePeriod}>
                            <SelectTrigger look="solo-pill" aria-label={t('Month')}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent look="solo-menu" align="start" sideOffset={8}>
                                {periods.map((p) => (
                                    <SelectItem key={p} value={p}>
                                        {formatPeriod(p)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <span className={styles.subtitle}>{t('Monthly close checklist')}</span>
                    </div>

                    {rows.length === 0 && (
                        <div className={styles.empty}>
                            {t('No clients in monthly close yet. Tag a client as a maintenance site or agency gig to add it here.')}
                        </div>
                    )}

                    {GROUPS.map(({ type, titleKey }) => {
                        const groupRows = rows.filter((row) => row.client.type === type);
                        if (groupRows.length === 0) {
                            return null;
                        }
                        return (
                            <section key={type} className={styles.group}>
                                <h2 className={styles.groupTitle}>
                                    {t(titleKey)} <span className={styles.groupCount}>{groupRows.length}</span>
                                </h2>
                                <div className={styles.grid}>{groupRows.map(renderCard)}</div>
                            </section>
                        );
                    })}
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** Figma: D3 flow F7, on this page's own styling: closable for the month being settled, its date once closed, else the month to close first. */
export function SettlementLine({ clientId, settlement }: { clientId: number; settlement: SettlementRow }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const [open, setOpen] = useState(false);
    const month = monthName(settlement.period, locale);

    return (
        <div className={styles.settlement} data-state={settlement.state}>
            <div className={styles.stepBody}>
                <span className={styles.stepLabel}>{t('Close {{month}}', { month })}</span>
                <span className={styles.stepHint}>
                    {settlement.state === 'ready' && settlement.closing !== null
                        ? t('Freezes the hours bank: balance {{balance}} h carries into {{next}}', {
                              balance: hoursNumber(settlement.closing, locale, true),
                              next: monthName(nextPeriod(settlement.period), locale),
                          })
                        : t('Freezes the hours bank for the month')}
                </span>
            </div>
            {settlement.state === 'ready' ? (
                <>
                    <button type="button" className={styles.startBtn} onClick={() => setOpen(true)}>
                        {t('Close the month')}
                    </button>
                    <SoloSurface day="paper" className={styles.dialogHost}>
                        <CloseMonthDialog clientId={clientId} period={settlement.period} open={open} onOpenChange={setOpen} />
                    </SoloSurface>
                </>
            ) : (
                <span className={styles.settlementState}>
                    {settlement.state === 'closed'
                        ? settlement.closed_on !== null
                            ? t('ledger_closed', { date: shortDate(settlement.closed_on, locale) })
                            : t('ledger_closed_undated')
                        : t('close {{month}} first', { month: monthName(settlement.active_period, locale) })}
                </span>
            )}
        </div>
    );
}
