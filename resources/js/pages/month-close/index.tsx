import FlashMessages from '@/components/flash-messages';
import { PhoneNavBar } from '@/components/solo/client-header';
import { KvRow } from '@/components/solo/kv-row';
import { Masthead } from '@/components/solo/masthead';
import { EmptyBlock, Poster, SectionAction, SectionHead } from '@/components/solo/primitives';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { splitClass, splitMainClass, splitSideClass } from '@/components/solo/split';
import { SoloSurface } from '@/components/solo/surface';
import { TaskRow } from '@/components/solo/task-row';
import { SoloToaster } from '@/components/solo/toaster';
import { Icon } from '@/components/ui/icon';
import { monthName, shortDate } from '@/lib/dates';
import clients from '@/routes/clients';
import monthClose from '@/routes/month-close';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, localeOf } from '../clients/components/client-list/format';
import { CloseMonthDialog } from '../clients/components/month-settlement';
import styles from './index.module.css';
import { monthLabel, stepLabel, type Settlement } from './steps';

export interface CloseRow {
    client: { id: number; name: string };
    total: number;
    resolved: number;
    next: { key: string; owner: 'you' | 'agent' } | null;
    sites: number;
    invoice: 'pending' | 'done' | 'skipped' | null;
    settlement: Settlement | null;
    finished_on: string | null;
}

interface MonthCloseProps extends SharedData {
    period: string;
    months: string[];
    clients: CloseRow[];
}

/** Figma: Zamknięcie miesiąca, direction A (961:23027): one row per client, its steps one click away. */
export default function Index() {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const { period, months, clients: rows } = usePage<MonthCloseProps>().props;
    const open = rows.filter((row) => row.finished_on === null);
    const finished = rows.filter((row) => row.finished_on !== null);
    const sum = (pick: (row: CloseRow) => number) => rows.reduce((total, row) => total + pick(row), 0);
    const steps = t('{{done}} of {{count}} steps', { done: sum((row) => row.resolved), count: sum((row) => row.total) });

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={`${monthLabel(period, locale)} · ${t('Month close')}`} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <PhoneNavBar back={{ href: clients.index().url, label: t('Clients') }} />
                <main className={`${splitClass} ${styles.grid}`}>
                    <div className={`${splitMainClass} ${styles.main}`}>
                        <div className={styles.head}>
                            <MonthTrail period={period} months={months} />
                            <Poster>{monthName(period, locale)}</Poster>
                            <p className={styles.sub}>
                                {t('Month close')} · {t('{{done}} of {{total}} closed', { done: finished.length, total: rows.length })} · {steps}
                            </p>
                        </div>
                        {rows.length === 0 && <EmptyBlock>{t('No clients in monthly close yet. Tag a client as a maintenance site or agency gig to add it here.')}</EmptyBlock>}
                        {open.length > 0 && (
                            <section className={styles.card}>
                                <SectionHead label={`${t('In the close')} · ${open.length}`} meta={steps} />
                                <ul className={styles.list}>
                                    {open.map((row) => (
                                        <CloseClientRow key={row.client.id} row={row} period={period} />
                                    ))}
                                </ul>
                            </section>
                        )}
                        {finished.length > 0 && (
                            <section className={styles.card}>
                                <SectionHead label={`${t('Finished clients')} · ${finished.length}`} quiet />
                                <ul className={styles.list}>
                                    {finished.map((row) => (
                                        <CloseClientRow key={row.client.id} row={row} period={period} />
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>
                    <aside className={`${splitSideClass} ${styles.side}`}>
                        <section className={styles.card}>
                            <SectionHead label={monthName(period, locale)} />
                            <dl className={styles.facts}>
                                <KvRow label={t('Clients in the close')} layout="split">
                                    {rows.length}
                                </KvRow>
                                <KvRow label={t('Maintenance sites')} layout="split">
                                    {sum((row) => row.sites)}
                                </KvRow>
                                <KvRow label={t('Steps')} layout="split">
                                    {t('{{done}} of {{total}}', { done: sum((row) => row.resolved), total: sum((row) => row.total) })}
                                </KvRow>
                                <KvRow label={t('Invoice drafts')} layout="split">
                                    {t('{{done}} of {{total}}', {
                                        done: rows.filter((row) => row.invoice === 'done').length,
                                        total: rows.filter((row) => row.invoice !== null).length,
                                    })}
                                </KvRow>
                            </dl>
                        </section>
                    </aside>
                </main>
            </div>
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** The month before, this one and the one after: the phone's segmented control, a caps trail from 768 px. */
export function MonthTrail({ period, months }: { period: string; months: string[] }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);

    return (
        <SegmentedControl
            label={t('Month')}
            width="full"
            wide="trail"
            current="step"
            value={period}
            options={months.map((month) => ({
                value: month,
                href: monthClose.index({ query: { period: month } }),
                label: <span>{monthLabel(month, locale)}</span>,
            }))}
        />
    );
}

/** Figma A2f / List row: the client, then progress and who acts next, the hours to close, or the day it finished. */
export function CloseClientRow({ row, period }: { row: CloseRow; period: string }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const settlement = row.settlement;
    const settling = row.next === null && settlement !== null && settlement.state !== 'closed';
    const chevron = <Icon name="chevron-right" size={16} />;

    return (
        <TaskRow
            compact
            task={{ task_id: 0, name: row.client.name, client: null, project: null, done: false }}
            href={row.total > 0 ? monthClose.show(row.client.id, { query: { period } }) : undefined}
            due={
                settling ? (
                    settlement.state === 'ready' ? (
                        settlement.closing !== null && `${hoursNumber(settlement.closing, locale, true)} h`
                    ) : (
                        t('close {{month}} first', { month: monthName(settlement.active_period, locale) })
                    )
                ) : row.finished_on !== null ? (
                    <>
                        <span className={styles.wide}>{t('finished {{date}}', { date: shortDate(row.finished_on, locale) })}</span>
                        <span className={styles.narrow}>{shortDate(row.finished_on, locale)}</span>
                    </>
                ) : (
                    <>
                        {t('{{done}} of {{total}}', { done: row.resolved, total: row.total })}
                        {row.next?.owner === 'you' && (
                            <>
                                <span className={styles.wide}> · {t('waiting on you: {{step}}', { step: stepLabel(row.next.key, t).toLocaleLowerCase(locale) })}</span>
                                <span className={styles.narrow}> · {t('You')}</span>
                            </>
                        )}
                        {row.next?.owner === 'agent' && ` · ${t('agent')}`}
                    </>
                )
            }
            trailing={settling ? settlement.state === 'ready' && <CloseMonthAction clientId={row.client.id} period={settlement.period} /> : row.total > 0 && chevron}
        />
    );
}

/** Zamknij miesiąc: the same confirm dialog as Przegląd and Rozliczenia. */
export function CloseMonthAction({ clientId, period }: { clientId: number; period: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <>
            <SectionAction onClick={() => setOpen(true)}>
                <span className={styles.wide}>{t('Close the month')}</span>
                <span className={styles.narrow}>{t('Close')}</span>
            </SectionAction>
            <SoloSurface day="paper" className={styles.dialogHost}>
                <CloseMonthDialog clientId={clientId} period={period} open={open} onOpenChange={setOpen} />
            </SoloSurface>
        </>
    );
}
