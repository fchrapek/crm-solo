import InertiaPagination from '@/components/inertia-pagination';
import { StatusDot } from '@/components/solo/chip';
import { HoursBar } from '@/components/solo/hours-bar';
import { useLinkPrefetch } from '@/components/solo/link-prefetch';
import { ListTable, ListTableCell, ListTableHead } from '@/components/solo/list-table';
import { Poster, SoloButton, SoloButtonLink } from '@/components/solo/primitives';
import { Row } from '@/components/solo/row';
import { DogStill } from '@/components/solo/shell/dog-still';
import { type PlaceResolver } from '@/components/solo/shell/place';
import { SoloSurface } from '@/components/solo/surface';
import { Tile, TileInset } from '@/components/solo/tile';
import { Icon } from '@/components/ui/icon';
import { InfoHint } from '@/components/ui/info-hint';
import { monthInstrumental, monthName } from '@/lib/dates';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import monthClose from '@/routes/month-close';
import { type ClientCooperationType, type LifecycleStage, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import type { TFunction } from 'i18next';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { type CooperationCounts, type ListCard, listQuery, type ListQuery, type ListStage } from './components/client-list/filters';
import { balanceOf, type ClientListHours, hoursLine, localeOf, rangeOf } from './components/client-list/format';
import { ClientDetailsDialog } from './components/details-tab/client-details-dialog';
import styles from './index.module.css';
import { clientListPlace, listFilters, noClients } from './place';

export interface ClientListRow {
    id: number;
    name: string;
    short_name: string;
    lifecycle_stage: LifecycleStage;
    cooperation_type: ClientCooperationType | null;
    is_archived: boolean;
    is_pinned: boolean;
    agent_live: boolean;
    agent_minutes: number | null;
    hours: ClientListHours;
    /** An account with its own fields: the overview values and the assignee. */
    fields?: Record<string, string | null>;
    assignee?: string | null;
}

interface ClientListPage {
    data: ClientListRow[];
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

interface IndexProps {
    filters: ListQuery;
    period: string;
    open_months: OpenMonths;
    stage_counts: Record<ListStage, number>;
    cooperation_counts: CooperationCounts;
    clients: ClientListPage;
    card: ListCard | null;
    [key: string]: unknown;
}

export type OpenMonths = { count: number; period: string | null; oldest: string | null };

/**
 * Figma: Clients (1087:3605, phone 1087:3858). The statuses and the further filters are the page's pills, the command
 * bar narrows the list to typed words, and the list is one tile: a table from 768 px, name and figures on a phone.
 */
export default function ClientsIndex() {
    const { filters: base, period, open_months: openMonths, clients: list, card: cardProp, stage_counts: stageCounts } = usePage<SharedData & IndexProps>().props;
    const card = cardProp ?? null;
    const filters = listFilters({ filters: base, card });
    const { t } = useTranslation();
    // The old /clients/create link lands here with ?new and opens the dialog.
    const [creating, setCreating] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('new'));

    if (noClients(stageCounts)) {
        return (
            <SoloSurface day="paper">
                <Head title={t('Clients')} />
                <FirstRun onCreate={() => setCreating(true)} />
                <ClientDetailsDialog open={creating} onOpenChange={setCreating} />
            </SoloSurface>
        );
    }

    return (
        <SoloSurface day="paper">
            <Head title={t('Clients')} />
            <main className={styles.page}>
                <header className={styles.head}>
                    <Poster>{t('Clients')}</Poster>
                    <SoloButton size="control" onClick={() => setCreating(true)}>
                        {t('New client')}
                    </SoloButton>
                </header>
                {openMonths.count > 0 && <OpenMonthsNote current={period} openMonths={openMonths} />}
                {card !== null && <SmsNote sms={card.sms} />}
                {filters.search !== '' && (
                    <Tile kind="list" as="div">
                        <Row
                            as="div"
                            rule={false}
                            title={t('Results for "{{query}}"', { query: filters.search })}
                            meta={list.total}
                            action={
                                <SoloButtonLink href={clients.index({ query: listQuery({ ...filters, search: '' }) })} variant="secondary" size="control">
                                    {t('Clear')}
                                </SoloButtonLink>
                            }
                        />
                    </Tile>
                )}
                <Tile kind="list" as="div">
                    {list.data.length === 0 ? (
                        <TileInset>
                            <p className={styles.empty}>{t('No clients found.')}</p>
                        </TileInset>
                    ) : (
                        <div className={styles.table}>{card !== null ? <CardTable card={card} rows={list.data} /> : <ClientTable rows={list.data} period={period} />}</div>
                    )}
                    <InertiaPagination
                        look="tile"
                        links={list.links}
                        preserveScroll
                        summary={t('{{range}} of {{total}}', { range: rangeOf(list.from ?? 0, list.to ?? 0), total: list.total })}
                    />
                </Tile>
            </main>
            <ClientDetailsDialog open={creating} onOpenChange={setCreating} />
        </SoloSurface>
    );
}

ClientsIndex.place = ((props, context) => clientListPlace({ ...props, card: props.card ?? null }, context)) satisfies PlaceResolver<IndexProps>;

/** Figma: First run (1095:45827, phone 1095:45957). No clients yet: the dog large, one line and the one next step. */
export function FirstRun({ onCreate }: { onCreate: () => void }) {
    const { t } = useTranslation();

    return (
        <main className={styles.page}>
            <Tile kind="plain" as="div">
                <span className={styles.firstDog}>
                    <DogStill size={192} pose="look-up" />
                </span>
                <h1 className={styles.firstTitle}>{t('You have no clients yet.')}</h1>
                <p className={styles.firstLine}>{t('first_run_line')}</p>
                <SoloButton size="control" className={styles.firstButton} onClick={onCreate}>
                    {t('New client')}
                </SoloButton>
            </Tile>
        </main>
    );
}

/** The line over the list, for the whole book, not the page. A phone gets the short form. */
export function openMonthsText(current: string, openMonths: OpenMonths, short: boolean, t: TFunction, language: string): string {
    const locale = localeOf(language);
    const name = monthName(current, locale);
    const values = { current: short ? name.charAt(0).toLocaleUpperCase(locale) + name.slice(1) : name, count: openMonths.count };

    if (openMonths.period !== null) {
        const month = monthInstrumental(openMonths.period, t);

        return short
            ? t('{{current}} · {{count}} clients with {{month}} open', { ...values, month })
            : t('It is {{current}} now · {{count}} clients with {{month}} open', { ...values, month });
    }

    return short
        ? t('{{current}} · {{count}} clients with an open month', values)
        : t('It is {{current}} now · {{count}} clients with an open month', values);
}

/** Figma: Tile / list · Open months. One row; Open leads to the month close of the oldest month still owed. */
export function OpenMonthsNote({ current, openMonths }: { current: string; openMonths: OpenMonths }) {
    const { t, i18n } = useTranslation();

    return (
        <Tile kind="list" as="div">
            <Row
                as="div"
                rule={false}
                title={
                    <>
                        <span className={styles.phoneOnly}>{openMonthsText(current, openMonths, true, t, i18n.language)}</span>
                        <span className={styles.wideOnly}>{openMonthsText(current, openMonths, false, t, i18n.language)}</span>
                    </>
                }
                action={
                    <SoloButtonLink
                        href={monthClose.index({ query: openMonths.oldest ? { period: openMonths.oldest } : {} })}
                        variant="secondary"
                        size="control"
                    >
                        {t('Open')}
                    </SoloButtonLink>
                }
            />
        </Tile>
    );
}

/** Today's scheduled SMS and the failed ones, with the way to the queue. */
function SmsNote({ sms }: { sms: ListCard['sms'] }) {
    const { t } = useTranslation();
    if (sms.today === 0 && sms.failed === 0) return null;
    const parts = [
        sms.today > 0 ? t('sms_today_count', { count: sms.today }) : null,
        sms.failed > 0 ? t('sms_failed_count', { count: sms.failed }) : null,
    ].filter(Boolean);

    return (
        <Tile kind="list" as="div">
            <Row
                as="div"
                rule={false}
                title={`SMS: ${parts.join(', ')}`}
                action={
                    <SoloButtonLink href={messages.index({ query: sms.failed > 0 ? { status: 'failed' } : {} }).url} variant="secondary" size="control">
                        {t('Open')}
                    </SoloButtonLink>
                }
            />
        </Tile>
    );
}

/** The name cell every list shares: the agent dot, the link to the record, the pin mark. */
function NameCell({ client }: { client: ClientListRow }) {
    const { t } = useTranslation();
    const prefetch = useLinkPrefetch();
    const href = clients.show({ client: client.id, tab: 'overview' });
    const agent =
        client.agent_minutes !== null
            ? t('{{agent}} working · {{minutes}} min', { agent: 'Claude', minutes: client.agent_minutes })
            : t('{{agent}} working', { agent: 'Claude' });

    return (
        <ListTableCell kind="title" truncate>
            <span className={styles.name}>
                {client.agent_live && (
                    <InfoHint
                        side="top"
                        trigger={
                            <button type="button" className={styles.agentDot} aria-label={agent}>
                                <StatusDot status="agent" />
                            </button>
                        }
                    >
                        {agent}
                    </InfoHint>
                )}
                <Link href={href} prefetch={prefetch(href)} className={styles.rowLink} title={client.name}>
                    {client.short_name}
                </Link>
                {client.is_pinned && <Icon name="pin" size={16} role="img" aria-label={t('Pinned')} className={styles.pin} />}
            </span>
        </ListTableCell>
    );
}

export function ClientTable({ rows, period }: { rows: ClientListRow[]; period: string }) {
    const { t, i18n } = useTranslation();

    return (
        <ListTable label={t('Clients')}>
            <thead>
                <tr>
                    <ListTableHead>{t('Client name')}</ListTableHead>
                    <ListTableHead className={styles.colHours}>{monthName(period, localeOf(i18n.language))}</ListTableHead>
                    <ListTableHead wide className={styles.colOpen}>
                        {t('Open month')}
                    </ListTableHead>
                    <ListTableHead align="end" className={styles.colBalance}>
                        {t('Balance')}
                    </ListTableHead>
                </tr>
            </thead>
            <tbody>
                {rows.map((client) => (
                    <ClientRow key={client.id} client={client} />
                ))}
            </tbody>
        </ListTable>
    );
}

/** Figma: ClientRow. A retainer reads its hours against the pool with the bar; a client whose earlier month is still open names it. */
export function ClientRow({ client }: { client: ClientListRow }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const balance = balanceOf(client.hours, locale);
    const idle = !client.hours.has_retainer && client.hours.logged === 0;

    return (
        <tr>
            <NameCell client={client} />
            <ListTableCell kind="number" tone={idle ? 'muted' : undefined} className={styles.hours}>
                <span className={styles.hoursLine}>
                    {client.hours.has_retainer && (
                        <span className={styles.bar}>
                            <HoursBar size="tile" used={client.hours.used} available={client.hours.available} />
                        </span>
                    )}
                    {hoursLine(client.hours, locale, t)}
                </span>
            </ListTableCell>
            <ListTableCell kind="meta" wide>
                {/* the hours belong to an earlier month still open, not the one in the column head */}
                {client.hours.behind && <span title={t('{{month}} still open', { month: monthName(client.hours.period, locale) })}>{monthName(client.hours.period, locale)}</span>}
            </ListTableCell>
            <ListTableCell
                kind="number"
                align="end"
                tone={balance.tone === 'over' ? 'over' : balance.tone === 'none' ? 'muted' : undefined}
            >
                {balance.tone === 'none' ? <span className={styles.wideOnly}>{balance.text}</span> : balance.text}
            </ListTableCell>
        </tr>
    );
}

/** An account with its own fields: the name, the overview fields, the assignee. A phone keeps the first field that has a value. */
function CardTable({ card, rows }: { card: ListCard; rows: ClientListRow[] }) {
    const { t } = useTranslation();

    return (
        <ListTable label={t('Clients')}>
            <thead>
                <tr>
                    <ListTableHead>{t('Client name')}</ListTableHead>
                    {card.columns.map((column) => (
                        <ListTableHead key={column.key} wide>
                            {column.label}
                        </ListTableHead>
                    ))}
                    <ListTableHead wide>{t('Assignee')}</ListTableHead>
                    <ListTableHead className={styles.phoneColumn} aria-hidden="true" />
                </tr>
            </thead>
            <tbody>
                {rows.map((client) => {
                    const first = card.columns.find((column) => client.fields?.[column.key]);

                    return (
                        <tr key={client.id}>
                            <NameCell client={client} />
                            {card.columns.map((column) => (
                                <ListTableCell key={column.key} wide tone={client.fields?.[column.key] ? undefined : 'muted'}>
                                    {client.fields?.[column.key] ?? '·'}
                                </ListTableCell>
                            ))}
                            <ListTableCell wide tone={client.assignee ? undefined : 'muted'}>
                                {client.assignee ?? '·'}
                            </ListTableCell>
                            <ListTableCell kind="meta" align="end" className={styles.phoneColumn}>
                                {first !== undefined ? client.fields?.[first.key] : null}
                            </ListTableCell>
                        </tr>
                    );
                })}
            </tbody>
        </ListTable>
    );
}
