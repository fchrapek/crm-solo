import FlashMessages from '@/components/flash-messages';
import { RecordHeader } from '@/components/solo/client-header';
import { KvRow } from '@/components/solo/kv-row';
import { Masthead } from '@/components/solo/masthead';
import { SectionAction, SectionHead } from '@/components/solo/primitives';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { TaskRow } from '@/components/solo/task-row';
import { SoloToaster } from '@/components/solo/toaster';
import { InfoHint } from '@/components/ui/info-hint';
import { monthName, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import monthClose from '@/routes/month-close';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, localeOf } from '../clients/components/client-list/format';
import { CloseMonthAction } from './index';
import styles from './index.module.css';
import { monthLabel, moveStep, stepLabel, type Settlement, type Step } from './steps';

interface CardProps extends SharedData {
    period: string;
    client: { id: number; name: string; short_name: string; type: 'maintenance' | 'gig' | null; report_mode: 'report' | 'summary_email' | 'none' | null; ssh: string | null };
    sites: { project_id: number; name: string; steps: Step[] }[];
    client_steps: Step[];
    settlement: Settlement | null;
}

const TYPE_LABEL = { maintenance: 'Maintenance sites', gig: 'Agency gigs' } as const;
const REPORT_LABEL = { report: 'PDF report', summary_email: 'Summary email', none: 'No report' } as const;

const resolved = (steps: Step[]) => steps.filter((step) => step.state !== 'pending').length;

/** Figma: Zamknięcie miesiąca, the client card (961:23255): the steps per site, the client's own, then the hours. */
export default function Show() {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { period, client, sites, client_steps: clientSteps, settlement } = usePage<CardProps>().props;
    const all = [...sites.flatMap((site) => site.steps), ...clientSteps];
    const list = monthClose.index({ query: { period } }).url;
    const month = monthName(period, locale);

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={`${client.short_name} · ${monthLabel(period, locale)}`} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    <RecordHeader
                        size="poster"
                        back={{ href: list, label: monthLabel(period, locale) }}
                        trail={[
                            { label: t('Clients'), href: clients.index().url },
                            { label: `${t('Month close')} · ${month}`, href: list },
                            { label: client.short_name },
                        ]}
                        title={client.short_name}
                        titleAttr={client.name}
                        afterTitle={
                            <p className={`${styles.sub} ${styles.recordSub}`}>
                                {[client.type && t(TYPE_LABEL[client.type]), month, t('{{done}} of {{count}} steps', { done: resolved(all), count: all.length })]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        }
                    />
                    <div className={`${splitClass} ${styles.cardGrid}`}>
                        <div className={`${splitMainClass} ${styles.main}`}>
                            {sites.map((site) => (
                                <StepBlock key={site.project_id} label={site.name} steps={site.steps} clientId={client.id} period={period} />
                            ))}
                            {clientSteps.length > 0 && <StepBlock label={t('Client level')} steps={clientSteps} clientId={client.id} period={period} />}
                        </div>
                        <aside className={`${splitSideClass} ${styles.side}`}>
                            {settlement !== null && (
                                <section className={styles.card}>
                                    <SectionHead label={t('Hours')} />
                                    <ul className={styles.list}>
                                        <TaskRow
                                            compact
                                            task={{ task_id: 0, name: t('Close {{month}}', { month }), client: null, project: null, done: false }}
                                            due={
                                                settlement.state === 'ready'
                                                    ? settlement.closing !== null && `${hoursNumber(settlement.closing, locale, true)} h`
                                                    : settlement.state === 'closed'
                                                      ? settlement.closed_on !== null
                                                          ? t('ledger_closed', { date: shortDate(settlement.closed_on, locale) })
                                                          : t('ledger_closed_undated')
                                                      : t('close {{month}} first', { month: monthName(settlement.active_period, locale) })
                                            }
                                            trailing={settlement.state === 'ready' && <CloseMonthAction clientId={client.id} period={settlement.period} />}
                                        />
                                    </ul>
                                </section>
                            )}
                            <section className={styles.card}>
                                <SectionHead label={t('Client details')} />
                                <dl className={styles.facts}>
                                    {client.report_mode !== null && (
                                        <KvRow label={t('Report')} layout="split">
                                            {t(REPORT_LABEL[client.report_mode])}
                                        </KvRow>
                                    )}
                                    {client.ssh !== null && (
                                        <KvRow label="SSH" layout="split">
                                            {client.ssh}
                                        </KvRow>
                                    )}
                                </dl>
                            </section>
                        </aside>
                    </div>
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

function StepBlock({ label, steps, clientId, period }: { label: string; steps: Step[]; clientId: number; period: string }) {
    const { t } = useTranslation();

    return (
        <section className={styles.card}>
            <SectionHead label={label} meta={t('{{done}} of {{total}}', { done: resolved(steps), total: steps.length })} />
            <ul className={styles.list}>
                {steps.map((step) => (
                    <StepRow key={`${step.project_id}-${step.key}`} step={step} clientId={clientId} period={period} />
                ))}
            </ul>
        </section>
    );
}

/** A Task row with no task behind it: the checkbox is done, Pomiń skips, Przywróć brings a skipped step back. */
export function StepRow({ step, clientId, period }: { step: Step; clientId: number; period: string }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const move = (state: Step['state']) => () => moveStep(clientId, period, step, state);
    const who = step.via === 'agent' ? t('agent') : step.via === 'web' ? t('You') : null;

    return (
        <TaskRow
            kind="client"
            plainDone
            task={{ task_id: 0, name: stepLabel(step.key, t), client: null, project: null, done: step.state === 'done', can_untick: true }}
            onComplete={move('done')}
            onUncomplete={move('pending')}
            due={
                <span className={styles.stepMeta}>
                    {step.state === 'skipped'
                        ? t('Skipped')
                        : step.state === 'done' && step.completed_on !== null
                          ? [shortDate(step.completed_on, locale), who].filter(Boolean).join(' · ')
                          : step.owner === 'you'
                            ? t('You')
                            : t('agent')}
                    {step.note && (
                        <InfoHint label={t('Note')} side="bottom">
                            {step.note}
                        </InfoHint>
                    )}
                </span>
            }
            trailing={
                step.state === 'skipped' ? (
                    <SectionAction onClick={move('pending')}>{t('Restore')}</SectionAction>
                ) : step.state === 'pending' ? (
                    <SectionAction onClick={move('skipped')}>{t('Skip')}</SectionAction>
                ) : null
            }
        />
    );
}
