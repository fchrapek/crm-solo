import FlashMessages from '@/components/flash-messages';
import InertiaPagination from '@/components/inertia-pagination';
import { BottomSheet } from '@/components/solo/bottom-sheet';
import { openSearch } from '@/components/solo/command-palette';
import { HoursBar } from '@/components/solo/hours-bar';
import { IconButton } from '@/components/solo/icon-button';
import { ListRow } from '@/components/solo/list-row';
import { LiveLine } from '@/components/solo/live-line';
import { Masthead } from '@/components/solo/masthead';
import { menuHeadClass, menuLabelClass, menuRowClass, menuValueClass } from '@/components/solo/menu';
import { Poster, SectionAction, SectionHead } from '@/components/solo/primitives';
import { SegmentedControl } from '@/components/solo/segmented-control';
import { SoloSurface } from '@/components/solo/surface';
import { SoloToaster } from '@/components/solo/toaster';
import { Icon } from '@/components/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { soloPillArrowClass, soloPillTriggerClass } from '@/components/ui/select';
import { monthInstrumental, monthMark, monthName } from '@/lib/dates';
import clients from '@/routes/clients';
import messages from '@/routes/messages';
import monthClose from '@/routes/month-close';
import { type ClientCooperationType, type LifecycleStage, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    CLEAR_FILTERS,
    type CooperationCounts,
    cooperationLabel,
    cooperationOptions,
    DESKTOP_STAGES,
    type ListCard,
    listQuery,
    type ListQuery,
    type ListStage,
    PHONE_STAGES,
    sameQuery,
    STAGE_LABEL,
} from './components/client-list/filters';
import { balanceOf, type ClientListHours, hoursLine, localeOf, rangeOf } from './components/client-list/format';
import { ClientDetailsDialog } from './components/details-tab/client-details-dialog';
import styles from './index.module.css';

export interface ClientListRow {
    id: number;
    name: string;
    short_name: string;
    lifecycle_stage: LifecycleStage;
    cooperation_type: ClientCooperationType | null;
    is_archived: boolean;
    is_pinned: boolean;
    agent_live: boolean;
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

type OpenMonths = { count: number; period: string | null; oldest: string | null };

const SEARCH_DEBOUNCE_MS = 250;

export default function ClientsIndex() {
    const {
        filters: base,
        period,
        open_months: openMonths,
        stage_counts,
        cooperation_counts,
        clients: list,
        card: cardProp,
    } = usePage<SharedData & IndexProps>().props;
    const card = cardProp ?? null;
    const filters: ListQuery = useMemo(() => (card === null ? base : { ...base, assignee: card.filters.assignee, f: card.filters.f }), [base, card]);
    const { t, i18n } = useTranslation();
    // What the page asks for runs ahead of the props; every visit from here replaces the entry, so the props never move on their own.
    const [query, setQuery] = useState(filters);
    const ask = (change: Partial<ListQuery>) => setQuery((asked) => ({ ...asked, ...change }));

    // The last query sent: the server may normalise the search further, and its answer must not be asked for again.
    const sent = useRef(filters);
    // The old /clients/create link lands here with ?nowy and opens the dialog.
    const [creating, setCreating] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('nowy'));

    useEffect(() => {
        const next = { ...query, search: query.search.trim() };
        if (sameQuery(next, filters) || sameQuery(next, sent.current)) return;
        const filtered = !sameQuery({ ...next, search: '' }, { ...sent.current, search: '' });
        const timer = window.setTimeout(
            () => {
                sent.current = next;
                router.get(clients.index.url(), listQuery(next), { preserveState: true, preserveScroll: true, replace: true });
            },
            filtered ? 0 : SEARCH_DEBOUNCE_MS,
        );

        return () => window.clearTimeout(timer);
    }, [query, filters]);

    return (
        <SoloSurface className={styles.page} day="paper">
            <Head title={t('Clients')} />
            <div className={styles.frame}>
                <Masthead current="clients" />
                <main>
                    <div className={styles.navBar}>
                        <IconButton icon="search" label={t('Search')} onClick={openSearch} />
                        <ListFilterSheet
                            filters={filters}
                            stageCounts={stage_counts}
                            cooperationCounts={cooperation_counts}
                            card={card}
                            onChange={ask}
                        />
                        <IconButton icon="plus" label={t('New client')} onClick={() => setCreating(true)} />
                    </div>
                    <header className={styles.head}>
                        <div className={styles.actionRow}>
                            <SectionAction onClick={() => setCreating(true)}>{t('New client')}</SectionAction>
                        </div>
                        <Poster>{t('Clients')}</Poster>
                        <div className={styles.filterBar} data-solo-fab="">
                            <label className={styles.searchField}>
                                <Icon name="search" size={20} className={styles.searchIcon} />
                                <input
                                    type="search"
                                    className={styles.search}
                                    value={query.search}
                                    placeholder={t('Search clients')}
                                    aria-label={t('Search clients')}
                                    onChange={(event) => ask({ search: event.target.value })}
                                />
                            </label>
                            <ListFilterPopover
                                filters={filters}
                                stageCounts={stage_counts}
                                cooperationCounts={cooperation_counts}
                                card={card}
                                total={list.total}
                                onChange={ask}
                            />
                        </div>
                        <p className={styles.stageLine}>
                            {card === null ? (
                                `${t(STAGE_LABEL[filters.stage])} · ${stage_counts[filters.stage]}`
                            ) : (
                                <CardFilterLabel card={card} filters={filters} total={list.total} />
                            )}
                        </p>
                        {card !== null && <SmsNote className={styles.notePhone} sms={card.sms} />}
                        {openMonths.count > 0 && <OpenMonthsNote className={styles.notePhone} current={period} openMonths={openMonths} short />}
                    </header>

                    <div className={styles.tableWrap}>
                        {openMonths.count > 0 && <OpenMonthsNote className={styles.noteTable} current={period} openMonths={openMonths} />}
                        {card !== null && <SmsNote className={styles.noteTable} sms={card.sms} />}
                        <div className={styles.listBlock}>
                            {card !== null ? (
                                <CardTable card={card} rows={list.data} />
                            ) : (
                                <table className={styles.table} aria-label={t('Clients')}>
                                    <colgroup>
                                        <col />
                                        <col className={styles.colHours} />
                                        <col className={styles.colBar} />
                                        <col className={styles.colBalance} />
                                        <col className={styles.colFlag} />
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('Client name')}</th>
                                            <th scope="col">{monthName(period, localeOf(i18n.language))}</th>
                                            <th scope="col" className={styles.barHead} aria-hidden="true" />
                                            <th scope="col">{t('Balance')}</th>
                                            <th scope="col">{t('Attention')} ↓</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {list.data.map((client) => (
                                            <ClientRow key={client.id} client={client} />
                                        ))}
                                        {list.data.length === 0 && (
                                            <tr>
                                                <td colSpan={5} className={styles.empty}>
                                                    {t('No clients found.')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            )}
                            <ClientList rows={list.data} card={card} />
                            <InertiaPagination
                                look="solo"
                                className={styles.pagination}
                                links={list.links}
                                preserveScroll
                                summary={t('{{range}} of {{total}}', { range: rangeOf(list.from ?? 0, list.to ?? 0), total: list.total })}
                            />
                        </div>
                    </div>
                </main>
            </div>
            <ClientDetailsDialog open={creating} onOpenChange={setCreating} />
            <FlashMessages toaster={<SoloToaster />} />
        </SoloSurface>
    );
}

/** Figma: D3 flow F1. One line over the table, for the whole book, not the page; it opens the month close of the oldest month still owed. Short on phones. */
export function OpenMonthsNote({
    current,
    openMonths,
    short = false,
    className,
}: {
    current: string;
    openMonths: OpenMonths;
    short?: boolean;
    className?: string;
}) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const name = monthName(current, locale);
    const values = { current: short ? name.charAt(0).toLocaleUpperCase(locale) + name.slice(1) : name, count: openMonths.count };
    const named = openMonths.period !== null;
    const text = short
        ? named
            ? t('{{current}} · {{count}} clients with {{month}} open', { ...values, month: monthInstrumental(openMonths.period!, t) })
            : t('{{current}} · {{count}} clients with an open month', values)
        : named
          ? t('It is {{current}} now · {{count}} clients with {{month}} open', { ...values, month: monthInstrumental(openMonths.period!, t) })
          : t('It is {{current}} now · {{count}} clients with an open month', values);

    return (
        <Link
            href={monthClose.index({ query: openMonths.oldest ? { period: openMonths.oldest } : {} })}
            className={[styles.openNote, className].filter(Boolean).join(' ')}
        >
            <span>
                {text}
                <span aria-hidden="true">{'\u00a0→'}</span>
            </span>
        </Link>
    );
}

export function ClientRow({ client }: { client: ClientListRow }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const balance = balanceOf(client.hours, locale);
    const idle = !client.hours.has_retainer && client.hours.logged === 0;

    return (
        <tr>
            <td className={styles.nameCell}>
                <span className={styles.nameLine}>
                    <Link href={clients.show({ client: client.id, tab: 'przeglad' })} className={styles.rowLink} title={client.name}>
                        {client.short_name}
                    </Link>
                    {client.is_pinned && <Icon name="pin" size={16} filled role="img" aria-label={t('Pinned')} className={styles.pinMark} />}
                </span>
            </td>
            <td data-tone={idle ? 'none' : undefined}>
                {hoursLine(client.hours, locale, t)}
                {/* the hours belong to an earlier month still open, not the one in the column head */}
                {client.hours.behind && (
                    <abbr className={styles.monthMark} title={t('{{month}} still open', { month: monthName(client.hours.period, locale) })}>
                        {monthMark(client.hours.period, locale)}
                    </abbr>
                )}
            </td>
            <td className={styles.barCell}>
                {client.hours.has_retainer && <HoursBar used={client.hours.used} available={client.hours.available} />}
            </td>
            <td data-tone={balance.tone}>{balance.text}</td>
            <td className={styles.flagCell}>{client.agent_live && <LiveLine size="sm">{t('{{agent}} working', { agent: 'claude' })}</LiveLine>}</td>
        </tr>
    );
}

/** Figma: Klient · Lista · mobile (328:818). The page's own rows, so a phone needs no other query. */
export function ClientList({ rows, card = null }: { rows: ClientListRow[]; card?: ListCard | null }) {
    const { t } = useTranslation();

    return (
        <ul className={styles.phoneList} aria-label={t('Clients')}>
            {rows.map((client) => (
                <li key={client.id}>{card !== null ? <PhoneCardRow client={client} card={card} /> : <PhoneClientRow client={client} />}</li>
            ))}
            {rows.length === 0 && <li className={styles.phoneEmpty}>{t('No clients found.')}</li>}
        </ul>
    );
}

function PhoneClientRow({ client }: { client: ClientListRow }) {
    const { t, i18n } = useTranslation();
    const locale = localeOf(i18n.language);
    const balance = balanceOf(client.hours, locale);

    return (
        <ListRow
            href={clients.show({ client: client.id, tab: 'przeglad' })}
            size="large"
            title={client.short_name}
            sub={hoursLine(client.hours, locale, t)}
            value={
                client.hours.has_retainer ? (
                    <span className={styles.phoneBalance} data-tone={balance.tone}>
                        {balance.text}
                    </span>
                ) : undefined
            }
            marks={{
                pinned: client.is_pinned,
                openMonth: client.hours.behind ? monthMark(client.hours.period, locale) : undefined,
                agent: client.agent_live ? t('{{agent}} working', { agent: 'claude' }) : undefined,
            }}
        />
    );
}

/** Figma: Klient · Lista · mobile · Filtr (sheet) (328:1219). Each choice applies at once; Gotowe closes. */
export function ListFilterSheet({
    filters,
    stageCounts,
    cooperationCounts,
    card = null,
    onChange,
}: {
    filters: ListQuery;
    stageCounts: Record<ListStage, number>;
    cooperationCounts: CooperationCounts;
    card?: ListCard | null;
    onChange: (change: Partial<ListQuery>) => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <BottomSheet
            open={open}
            onOpenChange={setOpen}
            title={t('Filters')}
            action="done"
            onClear={() => onChange({ ...CLEAR_FILTERS, ...(card !== null ? { assignee: null, f: {} } : {}) })}
            trigger={<IconButton icon="filter" label={t('Filter clients')} />}
        >
            <ListFilterBody filters={filters} stageCounts={stageCounts} cooperationCounts={cooperationCounts} card={card} onChange={onChange} />
        </BottomSheet>
    );
}

/** Figma: Menu / Filter (219:382). Status and Współpraca in one panel under the pill, every stage including Archived. */
export function ListFilterPopover({
    filters,
    stageCounts,
    cooperationCounts,
    card = null,
    total = 0,
    onChange,
}: {
    filters: ListQuery;
    stageCounts: Record<ListStage, number>;
    cooperationCounts: CooperationCounts;
    card?: ListCard | null;
    /** The clients the filter leaves, for an account with its own fields. */
    total?: number;
    onChange: (change: Partial<ListQuery>) => void;
}) {
    const { t } = useTranslation();
    const label =
        card !== null
            ? null
            : [
                  `${t(STAGE_LABEL[filters.stage])} · ${stageCounts[filters.stage]}`,
                  filters.cooperation_type ? t(cooperationLabel(filters.cooperation_type)) : null,
              ]
                  .filter(Boolean)
                  .join(' · ');

    return (
        <Popover>
            <PopoverTrigger className={[soloPillTriggerClass, styles.filterSelect].join(' ')} title={t('Filter clients')}>
                {card !== null ? <CardFilterLabel card={card} filters={filters} total={total} /> : label}
                <span className={soloPillArrowClass} aria-hidden="true">
                    ↓
                </span>
            </PopoverTrigger>
            <PopoverContent look="solo-menu" align="end" sideOffset={8} aria-label={t('Filters')}>
                <ListFilterBody
                    filters={filters}
                    stageCounts={stageCounts}
                    cooperationCounts={cooperationCounts}
                    card={card}
                    onChange={onChange}
                    stages={DESKTOP_STAGES}
                    look="menu"
                />
            </PopoverContent>
        </Popover>
    );
}

export function ListFilterBody({
    filters,
    stageCounts,
    cooperationCounts,
    card = null,
    onChange,
    stages = PHONE_STAGES,
    look = 'sheet',
}: {
    filters: ListQuery;
    stageCounts: Record<ListStage, number>;
    cooperationCounts: CooperationCounts;
    card?: ListCard | null;
    onChange: (change: Partial<ListQuery>) => void;
    stages?: ListStage[];
    /** sheet: the phone sheet, segments over list rows; menu: Figma Menu / Filter, every status as a row. */
    look?: 'sheet' | 'menu';
}) {
    const { t } = useTranslation();
    const cooperation = cooperationOptions(cooperationCounts, filters.cooperation_type).map((option) => ({
        key: option.value ?? 'all',
        label: t(option.label),
        count: option.count,
        active: option.active,
        select: () => onChange({ cooperation_type: option.value }),
    }));
    const showCooperation = card === null || card.billing;
    const cardGroups = card === null ? [] : cardFilterGroups(card, filters, onChange, t);

    if (look === 'menu') {
        return (
            <>
                <MenuRows
                    head={t('Status')}
                    options={stages.map((stage) => ({
                        key: stage,
                        label: t(STAGE_LABEL[stage]),
                        count: stageCounts[stage],
                        active: filters.stage === stage,
                        select: () => onChange({ stage }),
                    }))}
                />
                {cardGroups.map((group) => (
                    <MenuRows key={group.key} head={group.head} options={group.options} />
                ))}
                {showCooperation && <MenuRows head={t('Cooperation')} options={cooperation} />}
            </>
        );
    }

    return (
        <>
            <div className={styles.sheetStatus}>
                <SectionHead kind="body" phone="plain" label={t('Status')} />
                <SegmentedControl
                    label={t('Client stage')}
                    value={filters.stage}
                    onChange={(stage) => onChange({ stage })}
                    options={stages.map((stage) => ({ value: stage, label: `${t(STAGE_LABEL[stage])} · ${stageCounts[stage]}` }))}
                />
            </div>
            {cardGroups.map((group) => (
                <div key={group.key}>
                    <SectionHead kind="body" label={group.head} />
                    <OptionRows options={group.options} />
                </div>
            ))}
            {showCooperation && (
                <>
                    <SectionHead kind="body" label={t('Cooperation')} />
                    <OptionRows options={cooperation} />
                </>
            )}
        </>
    );
}

type FilterOption = { key: string; label: string; count: number; active: boolean; select: () => void };

/** Figma: Menu / Row, Head then Item rows; the chosen row is red, with no check. */
function MenuRows({ head, options }: { head: string; options: FilterOption[] }) {
    return (
        <>
            <p className={menuHeadClass}>{head}</p>
            {options.map((option) => (
                <button key={option.key} type="button" className={menuRowClass} aria-pressed={option.active} onClick={option.select}>
                    <span className={menuLabelClass}>{option.label}</span>
                    <span className={menuValueClass}>{option.count}</span>
                </button>
            ))}
        </>
    );
}

function OptionRows({ options }: { options: FilterOption[] }) {
    return (
        <ul className={styles.sheetOptions}>
            {options.map((option) => (
                <li key={option.key}>
                    <ListRow
                        title={option.label}
                        value={option.count}
                        trailing={option.active ? <Icon name="check" size={16} /> : null}
                        aria-pressed={option.active}
                        className={option.active ? styles.optionActive : undefined}
                        onClick={option.select}
                    />
                </li>
            ))}
        </ul>
    );
}

type Translate = ReturnType<typeof useTranslation>['t'];

/** Opiekun, then one group per choice field; each row applies at once. */
function cardFilterGroups(card: ListCard, filters: ListQuery, onChange: (change: Partial<ListQuery>) => void, t: Translate) {
    const current = filters.f ?? {};

    return [
        {
            key: 'assignee',
            head: t('Assignee'),
            options: card.assignees.map((option) => ({
                key: String(option.value ?? 'all'),
                label:
                    option.value === 'me'
                        ? t('My clients')
                        : option.value === null
                          ? t('All clients')
                          : option.value === 'none'
                            ? t('No assignee')
                            : (option.label ?? ''),
                count: option.count,
                active: (filters.assignee ?? null) === option.value,
                select: () => onChange({ assignee: option.value }),
            })),
        },
        ...card.facets.map((facet) => ({
            key: `f-${facet.key}`,
            head: facet.label,
            options: facet.options.map((option) => ({
                key: option.value ?? 'all',
                label: option.value === null ? t('All') : (option.label ?? option.value),
                count: option.count,
                active: (current[facet.key] ?? null) === option.value,
                select: () => {
                    const next = { ...current };
                    if (option.value === null) delete next[facet.key];
                    else next[facet.key] = option.value;
                    onChange({ f: next });
                },
            })),
        })),
    ];
}

/** "Moi · Wizja: do umówienia · 6": what the filter shows, then how many. */
function CardFilterLabel({ card, filters, total }: { card: ListCard; filters: ListQuery; total: number }) {
    const { t } = useTranslation();
    const assignee = card.assignees.find((option) => option.value === (filters.assignee ?? null));
    const parts = [
        filters.assignee === 'me'
            ? t('Mine')
            : filters.assignee === 'none'
              ? t('No assignee')
              : filters.assignee
                ? (assignee?.label ?? null)
                : t('All clients'),
        ...card.facets
            .filter((facet) => (filters.f ?? {})[facet.key] !== undefined)
            .map((facet) => `${facet.label}: ${facet.options.find((option) => option.value === filters.f?.[facet.key])?.label ?? ''}`),
        String(total),
    ];

    return <>{parts.filter(Boolean).join(' · ')}</>;
}

/** Figma: the SMS line over the table (606:4373): today's scheduled and the failed ones, to the queue. */
function SmsNote({ sms, className }: { sms: ListCard['sms']; className?: string }) {
    const { t } = useTranslation();
    if (sms.today === 0 && sms.failed === 0) return null;
    const parts = [
        sms.today > 0 ? t('sms_today_count', { count: sms.today }) : null,
        sms.failed > 0 ? t('sms_failed_count', { count: sms.failed }) : null,
    ].filter(Boolean);

    return (
        <Link
            href={messages.index({ query: sms.failed > 0 ? { status: 'failed' } : {} }).url}
            className={[styles.openNote, className].filter(Boolean).join(' ')}
        >
            <span>
                SMS: {parts.join(', ')}
                <span aria-hidden="true">{'\u00a0→'}</span>
            </span>
        </Link>
    );
}

/** Figma: Client table head and Client row (604:3721, 604:3734): the name, the overview fields, the assignee. */
function CardTable({ card, rows }: { card: ListCard; rows: ClientListRow[] }) {
    const { t } = useTranslation();

    return (
        <table className={styles.table} aria-label={t('Clients')}>
            <thead>
                <tr>
                    <th scope="col">{t('Client name')}</th>
                    {card.columns.map((column) => (
                        <th key={column.key} scope="col">
                            {column.label}
                        </th>
                    ))}
                    <th scope="col">{t('Assignee')}</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((client) => (
                    <tr key={client.id}>
                        <td className={styles.nameCell}>
                            <span className={styles.nameLine}>
                                <Link href={clients.show({ client: client.id, tab: 'przeglad' })} className={styles.rowLink} title={client.name}>
                                    {client.short_name}
                                </Link>
                                {client.is_pinned && (
                                    <Icon name="pin" size={16} filled role="img" aria-label={t('Pinned')} className={styles.pinMark} />
                                )}
                            </span>
                        </td>
                        {card.columns.map((column) => (
                            <td key={column.key} data-tone={client.fields?.[column.key] ? undefined : 'none'}>
                                {client.fields?.[column.key] ?? '·'}
                            </td>
                        ))}
                        <td data-tone={client.assignee ? undefined : 'none'}>{client.assignee ?? '·'}</td>
                    </tr>
                ))}
                {rows.length === 0 && (
                    <tr>
                        <td colSpan={card.columns.length + 2} className={styles.empty}>
                            {t('No clients found.')}
                        </td>
                    </tr>
                )}
            </tbody>
        </table>
    );
}

function PhoneCardRow({ client, card }: { client: ClientListRow; card: ListCard }) {
    const first = card.columns.find((column) => client.fields?.[column.key]);

    return (
        <ListRow
            href={clients.show({ client: client.id, tab: 'przeglad' })}
            size="large"
            title={client.short_name}
            value={first !== undefined ? <span className={styles.phoneValue}>{client.fields?.[first.key]}</span> : undefined}
            marks={{ pinned: client.is_pinned }}
        />
    );
}
