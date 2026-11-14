import { router } from '@inertiajs/react';
import { type KeyboardEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { jsonRequest } from '@/lib/json-request';
import { search } from '@/routes';
import { capitalize, monthName } from '@/lib/dates';
import { cooperationLabel } from '@/pages/clients/components/client-list/filters';
import { type ClientCooperationType } from '@/types';
import styles from './command-palette.module.css';
import { GroupHeading } from './tile';

export type SearchGroupName = 'places' | 'clients' | 'tasks' | 'contacts' | 'leads' | 'reports' | 'invoices';

export interface SearchItem {
    id: number;
    title: string;
    client: string | null;
    url: string | null;
    archived: boolean;
    detail: Record<string, string | number | boolean | null>;
}

export interface SearchGroup {
    group: SearchGroupName;
    total: number;
    items: SearchItem[];
}

const OPEN_EVENT = 'solo:search';
const GROUP_LABEL: Record<SearchGroupName, string> = {
    places: 'Places',
    clients: 'Clients',
    tasks: 'Tasks',
    contacts: 'Contacts',
    leads: 'Leads',
    reports: 'Reports',
    invoices: 'Invoices',
};

/** Puts the cursor in the command bar from anywhere, such as the phone search button. */
export function openSearch(): void {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

type GroupRow = { kind: 'item'; group: SearchGroupName; item: SearchItem } | { kind: 'more'; group: SearchGroupName; left: number };
type Row = GroupRow | { kind: 'filter'; group: null };

/** The page's own list, narrowed to the typed words: the first row of the results on a page that offers it. */
export interface SearchFilter {
    label: string;
    href: (query: string) => string;
}

/** A screen the search can open by its name, such as one the rail has no item for. */
export interface SearchPlace {
    label: string;
    url: string;
}

const plain = (text: string) =>
    text
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/ł/gi, 'l')
        .toLowerCase();

/** The screens whose name holds the typed words, as the first group; it is matched here, so it needs no request. */
export function placeGroup(query: string, places: SearchPlace[]): SearchGroup | null {
    const words = plain(query).split(/\s+/).filter(Boolean);
    const items = places
        .filter((place) => words.length > 0 && words.every((word) => plain(place.label).includes(word)))
        .map((place, index): SearchItem => ({ id: index, title: place.label, client: null, url: place.url, archived: false, detail: {} }));

    return items.length > 0 ? { group: 'places', total: items.length, items } : null;
}

/** The palette's rows in reading order: each group's items, then its "pokaż więcej" while more are left. */
export function paletteRows(groups: SearchGroup[], more: Partial<Record<SearchGroupName, SearchItem[]>>): GroupRow[] {
    return groups.flatMap((group) => {
        const items = more[group.group] ?? group.items;
        const left = group.total - items.length;

        return [...items.map((item): GroupRow => ({ kind: 'item', group: group.group, item })), ...(left > 0 && more[group.group] === undefined ? [{ kind: 'more', group: group.group, left } as GroupRow] : [])];
    });
}

/**
 * The one search: the request, the result rows and the keys that walk them. The command bar renders it;
 * ⌘K or openSearch() asks for the field through onOpen.
 */
export function useSearch({
    open,
    onOpen,
    onClose,
    filter,
    places = [],
}: {
    open: boolean;
    onOpen: () => void;
    onClose: () => void;
    filter?: SearchFilter;
    places?: SearchPlace[];
}) {
    const [query, setQuery] = useState('');
    const [archived, setArchived] = useState(false);
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [more, setMore] = useState<Partial<Record<SearchGroupName, SearchItem[]>>>({});
    const [searched, setSearched] = useState('');
    const [active, setActive] = useState(0);
    // Which result set is on screen, counted up with every one, so a late "pokaż więcej" for an earlier set is dropped.
    const resultSet = useRef(0);

    const close = useCallback(() => {
        resultSet.current += 1;
        setQuery('');
        setGroups([]);
        setMore({});
        setSearched('');
        setArchived(false);
        onClose();
    }, [onClose]);

    useEffect(() => {
        const toggle = (event: globalThis.KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                if (open) close();
                else onOpen();
            }
        };
        window.addEventListener('keydown', toggle);
        window.addEventListener(OPEN_EVENT, onOpen);

        return () => {
            window.removeEventListener('keydown', toggle);
            window.removeEventListener(OPEN_EVENT, onOpen);
        };
    }, [open, onOpen, close]);

    const trimmed = query.trim();
    useEffect(() => {
        if (!open || trimmed.length < 2) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            jsonRequest<{ groups: SearchGroup[] }>(search.url({ query: { q: trimmed, archived: archived ? 1 : undefined } }), 'GET', undefined, controller.signal)
                .then((result) => {
                    resultSet.current += 1;
                    setGroups(result.groups);
                    setMore({});
                    setSearched(trimmed);
                    setActive(0);
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, trimmed, archived]);

    function loadMore(group: SearchGroupName) {
        const asked = resultSet.current;
        jsonRequest<{ groups: SearchGroup[] }>(search.url({ query: { q: trimmed, archived: archived ? 1 : undefined, group } }), 'GET')
            .then((result) => {
                if (resultSet.current === asked) setMore((current) => ({ ...current, [group]: result.groups[0]?.items ?? [] }));
            })
            .catch(() => undefined);
    }

    // The keyboard row stays in sight when the arrows walk past the visible ones.
    useEffect(() => {
        document.getElementById(`palette-row-${active}`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const named = trimmed.length < 2 ? null : placeGroup(trimmed, places);
    const shown = trimmed.length < 2 ? [] : [...(named !== null ? [named] : []), ...groups];
    const rows: Row[] = [...(filter !== undefined && trimmed.length >= 2 ? [{ kind: 'filter', group: null } as Row] : []), ...paletteRows(shown, more)];

    function choose(row: Row | undefined) {
        if (row === undefined) return;
        if (row.kind === 'filter') {
            const href = filter?.href(trimmed);
            close();
            if (href !== undefined) router.visit(href);

            return;
        }
        if (row.kind === 'more') {
            loadMore(row.group);

            return;
        }
        if (row.item.url !== null) {
            close();
            router.visit(row.item.url);
        }
    }

    /** Arrows walk the rows, Enter opens the keyboard row, Escape leaves the search. */
    function onKeyDown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(rows[active]);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((current) => (rows.length === 0 ? 0 : (current + step + rows.length) % rows.length));
        }
    }

    return {
        query,
        setQuery,
        archived,
        toggleArchived: () => setArchived((current) => !current),
        groups: shown,
        rows,
        active,
        setActive,
        choose,
        onKeyDown,
        close,
        /** The query has an answer on screen, even an empty one. */
        answered: searched !== '' && trimmed === searched,
        searched,
        filter: filter !== undefined && trimmed.length >= 2 ? { label: filter.label, query: trimmed } : null,
        activeId: rows[active] ? `palette-row-${active}` : undefined,
    };
}

export type Search = ReturnType<typeof useSearch>;

/** The result groups, the empty line and the archived switch, under the command bar's field. */
export function SearchResults({ search: found }: { search: Search }) {
    const { t } = useTranslation();

    return (
        <>
            <div id="palette-results" role="listbox" aria-label={t('Search')} className={styles.results}>
                {found.filter !== null && (
                    <button
                        id="palette-row-0"
                        type="button"
                        role="option"
                        aria-selected={found.active === 0}
                        data-highlighted={found.active === 0 ? '' : undefined}
                        className={styles.row}
                        tabIndex={-1}
                        onMouseMove={() => found.setActive(0)}
                        onClick={() => found.choose(found.rows[0])}
                    >
                        <span className={styles.title}>{found.filter.label}</span>
                        <span className={styles.caption}>{found.filter.query}</span>
                    </button>
                )}
                {found.groups.map((group) => (
                    <PaletteGroup key={group.group} group={group} rows={found.rows} active={found.active} onHover={found.setActive} onChoose={found.choose} />
                ))}
                {found.answered && found.groups.length === 0 && <p className={styles.empty}>{t('No results for "{{query}}"', { query: found.searched })}</p>}
            </div>
            <div className={styles.footer}>
                <button type="button" className={styles.archived} aria-pressed={found.archived} onClick={found.toggleArchived}>
                    {found.archived ? t('Hide archived') : t('Show archived too')}
                </button>
                <SearchHints />
            </div>
        </>
    );
}

/** The keys that walk the results; a phone has no use for them. */
export function SearchHints() {
    const { t } = useTranslation();

    return (
        <p className={styles.hints} aria-hidden="true">
            <span>↑↓ {t('select')}</span>
            <span>↵ {t('open')}</span>
            <span>
                <b>esc</b> {t('close')}
            </span>
        </p>
    );
}

function PaletteGroup({
    group,
    rows,
    active,
    onHover,
    onChoose,
}: {
    group: SearchGroup;
    rows: Row[];
    active: number;
    onHover: (index: number) => void;
    onChoose: (row: Row) => void;
}) {
    const { t, i18n } = useTranslation();

    return (
        <div role="group" aria-label={t(GROUP_LABEL[group.group])} className={styles.group}>
            <GroupHeading as="p" label={t(GROUP_LABEL[group.group])} note={group.total} />
            {rows.map((row, index) =>
                row.group !== group.group ? null : (
                    <button
                        key={row.kind === 'item' ? `${row.group}-${row.item.id}` : `${row.group}-${row.kind}`}
                        id={`palette-row-${index}`}
                        type="button"
                        role="option"
                        aria-selected={index === active}
                        data-highlighted={index === active ? '' : undefined}
                        className={styles.row}
                        tabIndex={-1}
                        onMouseMove={() => onHover(index)}
                        onClick={() => onChoose(row)}
                    >
                        {row.kind === 'item' ? (
                            <>
                                <span className={styles.title}>{itemTitle(row.group, row.item, t, i18n.language)}</span>
                                <span className={styles.caption}>{itemCaption(row.group, row.item, t)}</span>
                            </>
                        ) : row.kind === 'more' ? (
                            <>
                                <span className={`${styles.title} ${styles.caption}`}>{t('Show more')} ↓</span>
                                <span className={styles.caption}>{t('{{count}} more', { count: row.left })}</span>
                            </>
                        ) : null}
                    </button>
                ),
            )}
        </div>
    );
}

type T = (key: string, options?: Record<string, unknown>) => string;

function itemTitle(group: SearchGroupName, item: SearchItem, t: T, locale: string): string {
    if (group === 'reports' && typeof item.detail.period === 'string') {
        return t('Report for {{month}}', { month: `${monthName(item.detail.period, locale)} ${item.detail.period.slice(0, 4)}` });
    }

    return item.title;
}

function itemCaption(group: SearchGroupName, item: SearchItem, t: T): string {
    const parts: (string | null)[] = (() => {
        switch (group) {
            case 'clients':
                return [typeof item.detail.type === 'string' ? t(cooperationLabel(item.detail.type as ClientCooperationType)) : null];
            case 'leads':
                return [typeof item.detail.stage === 'string' ? capitalize(t(`lead_stage_${item.detail.stage}`, { defaultValue: item.detail.stage })) : null, item.client];
            case 'reports':
                return [item.client, typeof item.detail.status === 'string' ? t(capitalize(item.detail.status)) : null];
            case 'invoices':
                return [
                    item.client,
                    typeof item.detail.net === 'number'
                        ? new Intl.NumberFormat('pl-PL', { style: 'currency', currency: String(item.detail.currency ?? 'PLN') }).format(item.detail.net)
                        : null,
                ];
            default:
                return [item.client];
        }
    })();

    return [...parts, item.archived ? t('archived') : null].filter(Boolean).join(' · ');
}
