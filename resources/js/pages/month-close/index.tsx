import { KvRow } from '@/components/solo/kv-row';
import { SoloButton, Stat } from '@/components/solo/primitives';
import { RecordFrame } from '@/components/solo/record-frame';
import { Row } from '@/components/solo/row';
import { Pills } from '@/components/solo/shell/pills';
import { type PlaceLinkPill, type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { GroupHeading, Tile, TileGrid, TileNote, TileRows, TileStack } from '@/components/solo/tile';
import { monthName, shortDate } from '@/lib/dates';
import monthClose from '@/routes/month-close';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { hoursNumber, localeOf } from '../clients/components/client-list/format';
import { CloseMonthDialog } from '../clients/components/month-settlement';
import styles from './index.module.css';
import { closeListUrl, FILTERS, filterOf, matchesFilter, owesHours, type CloseFilter, type CloseRow } from './filters';
import { invoiceDrafts, monthLabel, stepLabel } from './steps';

export { closeListUrl, filterOf, matchesFilter, type CloseFilter, type CloseRow } from './filters';

interface MonthCloseProps extends SharedData {
    period: string;
    months: string[];
    clients: CloseRow[];
}

const FILTER_LABEL: Record<CloseFilter, string> = {
    all: 'close_filter_all',
    you: 'close_filter_you',
    agent: 'close_filter_agent',
    hours: 'close_filter_hours',
    finished: 'close_filter_finished',
};

/** The filter pills with their counts; each is a link, so the choice survives a reload. */
export function filterPills(rows: CloseRow[], period: string, current: CloseFilter, t: TFunction): PlaceLinkPill[] {
    return FILTERS.map((filter) => ({
        label: t(FILTER_LABEL[filter]),
        href: closeListUrl(period, filter),
        active: filter === current,
        count: rows.filter((row) => matchesFilter(row, filter)).length,
        visit: { preserveState: true, preserveScroll: true, replace: true },
    }));
}

/** Figma: Month close · list (1094:34610, phone 1094:34789): one row per client, its steps one click away. */
export default function Index() {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const page = usePage<MonthCloseProps>();
    const { period, clients: rows } = page.props;
    const filter = filterOf(page.url);
    const shown = rows.filter((row) => matchesFilter(row, filter));
    const open = shown.filter((row) => row.finished_on === null);
    const finished = shown.filter((row) => row.finished_on !== null);
    const closed = rows.filter((row) => row.finished_on !== null).length;
    const sum = (pick: (row: CloseRow) => number) => rows.reduce((total, row) => total + pick(row), 0);
    const steps = t('{{done}} of {{count}} steps', { done: sum((row) => row.resolved), count: sum((row) => row.total) });

    return (
        <SoloSurface day="paper">
            <Head title={`${monthLabel(period, locale)} · ${t('Month close')}`} />
            <main className={styles.page}>
                <RecordFrame
                    title={monthLabel(period, locale)}
                    noteKind="line"
                    note={`${t('Month close')} · ${t('{{done}} of {{total}} closed', { done: closed, total: rows.length })} · ${steps}`}
                />
                {rows.length > 0 && (
                    <div className={styles.filters}>
                        <span className={styles.caption}>{t('Show')}</span>
                        <Pills items={filterPills(rows, period, filter, t)} label={t('Show')} />
                    </div>
                )}
                <TileGrid>
                    <TileStack>
                        {rows.length === 0 && (
                            <Tile kind="plain">
                                <TileNote>{t('No clients in monthly close yet. Tag a client as a maintenance site or agency gig to add it here.')}</TileNote>
                            </Tile>
                        )}
                        {rows.length > 0 && shown.length === 0 && (
                            <Tile kind="plain">
                                <TileNote>{t('close_filter_empty')}</TileNote>
                            </Tile>
                        )}
                        {open.length > 0 && (
                            <Tile kind="list">
                                <GroupHeading label={t('In the close')} note={open.length} end={steps} />
                                <TileRows>
                                    {open.map((row, index) => (
                                        <CloseClientRow key={row.client.id} row={row} period={period} rule={index > 0} />
                                    ))}
                                </TileRows>
                            </Tile>
                        )}
                        {finished.length > 0 && (
                            <Tile kind="list">
                                <GroupHeading label={t('Finished clients')} note={finished.length} />
                                <TileRows>
                                    {finished.map((row, index) => (
                                        <CloseClientRow key={row.client.id} row={row} period={period} rule={index > 0} />
                                    ))}
                                </TileRows>
                            </Tile>
                        )}
                    </TileStack>
                    <TileStack as="aside">
                        <Tile kind="plain">
                            <Stat
                                kind="tile"
                                label={monthName(period, locale)}
                                value={t('{{done}} of {{total}}', { done: closed, total: rows.length })}
                                sub={t('clients closed')}
                            />
                            <dl className={styles.facts}>
                                <KvRow layout="tile" label={t('Clients in the close')}>
                                    {rows.length}
                                </KvRow>
                                <KvRow layout="tile" label={t('Maintenance sites')}>
                                    {sum((row) => row.sites)}
                                </KvRow>
                                <KvRow layout="tile" label={t('Steps')}>
                                    {t('{{done}} of {{total}}', { done: sum((row) => row.resolved), total: sum((row) => row.total) })}
                                </KvRow>
                                <KvRow layout="tile" label={t('Invoice drafts')}>
                                    {t('{{done}} of {{total}}', invoiceDrafts(rows))}
                                </KvRow>
                            </dl>
                        </Tile>
                    </TileStack>
                </TileGrid>
            </main>
        </SoloSurface>
    );
}

/** Figma: Row. The client, then progress and who acts next, the hours to close, or the day it finished. */
export function CloseClientRow({ row, period, rule = true }: { row: CloseRow; period: string; rule?: boolean }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const settlement = row.settlement;
    const href = row.total > 0 ? monthClose.show(row.client.id, { query: { period } }).url : undefined;

    if (owesHours(row) && settlement !== null) {
        return settlement.state === 'ready' ? (
            <Row
                rule={rule}
                title={row.client.name}
                href={href}
                time={settlement.closing !== null ? `${hoursNumber(settlement.closing, locale, true)} h` : undefined}
                timeTone={settlement.closing !== null && settlement.closing < 0 ? 'over' : 'neutral'}
                action={<CloseMonthAction clientId={row.client.id} period={settlement.period} />}
            />
        ) : (
            <Row rule={rule} title={row.client.name} href={href} meta={t('close {{month}} first', { month: monthName(settlement.active_period, locale) })} />
        );
    }

    if (row.finished_on !== null) {
        return (
            <Row
                rule={rule}
                title={row.client.name}
                href={href}
                wideMeta={t('finished {{date}}', { date: shortDate(row.finished_on, locale) })}
                meta={<span className={styles.narrow}>{shortDate(row.finished_on, locale)}</span>}
            />
        );
    }

    return (
        <Row
            rule={rule}
            title={row.client.name}
            href={href}
            time={t('{{done}} of {{total}}', { done: row.resolved, total: row.total })}
            wideMeta={
                row.next?.owner === 'you'
                    ? t('waiting on you: {{step}}', { step: stepLabel(row.next.key, t).toLocaleLowerCase(locale) })
                    : row.next?.owner === 'agent'
                      ? t('agent')
                      : undefined
            }
            meta={row.next !== null ? <span className={styles.narrow}>{row.next.owner === 'you' ? t('You') : t('agent')}</span> : undefined}
        />
    );
}

/** Close the month: the same confirm dialog as Overview and Billing. */
export function CloseMonthAction({ clientId, period, primary = false }: { clientId: number; period: string; primary?: boolean }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <>
            <SoloButton variant={primary ? 'primary' : 'secondary'} size="control" aria-haspopup="dialog" onClick={() => setOpen(true)}>
                {primary ? (
                    t('Close the month')
                ) : (
                    <>
                        <span className={styles.wide}>{t('Close the month')}</span>
                        <span className={styles.narrow}>{t('Close')}</span>
                    </>
                )}
            </SoloButton>
            <CloseMonthDialog clientId={clientId} period={period} open={open} onOpenChange={setOpen} />
        </>
    );
}

/** The month before, this one and the one after as the pills; the list keeps its place under Month close. */
export function monthPills(months: string[], period: string, locale: string): PlaceLinkPill[] {
    return months.map((month) => ({ label: monthLabel(month, locale), href: closeListUrl(month), active: month === period }));
}

Index.place = (({ period, months }, { t, locale }) => ({
    tokens: [{ label: t('Month close'), href: monthClose.index().url }, { label: monthLabel(period, locale) }],
    pills: monthPills(months, period, locale),
})) satisfies PlaceResolver<MonthCloseProps>;
