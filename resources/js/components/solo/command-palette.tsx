import { router } from '@inertiajs/react';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { jsonRequest } from '@/lib/json-request';
import { search } from '@/routes';
import { capitalize, monthName } from '@/lib/dates';
import { cooperationLabel } from '@/pages/clients/components/client-list/filters';
import { type ClientCooperationType } from '@/types';
import { menuBoxClass, menuHeadClass, menuLabelClass, menuRowClass, menuValueClass } from './menu';
import styles from './command-palette.module.css';

export type SearchGroupName = 'clients' | 'tasks' | 'contacts' | 'leads' | 'reports' | 'invoices';

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
    clients: 'Clients',
    tasks: 'Tasks',
    contacts: 'Contacts',
    leads: 'Leads',
    reports: 'Reports',
    invoices: 'Invoices',
};

/** Opens the palette from anywhere, such as the phone search button. */
export function openSearch(): void {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

type Row = { kind: 'item'; group: SearchGroupName; item: SearchItem } | { kind: 'more'; group: SearchGroupName; left: number };

/** The palette's rows in reading order: each group's items, then its "pokaż więcej" while more are left. */
export function paletteRows(groups: SearchGroup[], more: Partial<Record<SearchGroupName, SearchItem[]>>): Row[] {
    return groups.flatMap((group) => {
        const items = more[group.group] ?? group.items;
        const left = group.total - items.length;

        return [...items.map((item): Row => ({ kind: 'item', group: group.group, item })), ...(left > 0 && more[group.group] === undefined ? [{ kind: 'more', group: group.group, left } as Row] : [])];
    });
}

/**
 * Figma: ⌘K Szukaj (567:10386, empty 569:10666, phone 569:10727). ⌘K or the header pill opens it: a 640 px box on desktop,
 * a full-screen sheet on phones. Five results per group, "pokaż więcej" loads up to twenty, archived records on request.
 */
export function CommandPalette() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [archived, setArchived] = useState(false);
    const [groups, setGroups] = useState<SearchGroup[]>([]);
    const [more, setMore] = useState<Partial<Record<SearchGroupName, SearchItem[]>>>({});
    const [searched, setSearched] = useState('');
    const [active, setActive] = useState(0);
    // Which result set is on screen, counted up with every one, so a late "pokaż więcej" for an earlier set is dropped.
    const resultSet = useRef(0);

    useEffect(() => {
        const toggle = (event: globalThis.KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };
        const show = () => setOpen(true);
        window.addEventListener('keydown', toggle);
        window.addEventListener(OPEN_EVENT, show);

        return () => {
            window.removeEventListener('keydown', toggle);
            window.removeEventListener(OPEN_EVENT, show);
        };
    }, []);

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

    function change(next: boolean) {
        setOpen(next);
        if (!next) {
            resultSet.current += 1;
            setQuery('');
            setGroups([]);
            setMore({});
            setSearched('');
            setArchived(false);
        }
    }

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

    const shown = trimmed.length < 2 ? [] : groups;
    const rows = paletteRows(shown, more);

    function choose(row: Row | undefined) {
        if (row === undefined) return;
        if (row.kind === 'more') {
            loadMore(row.group);

            return;
        }
        if (row.item.url !== null) {
            change(false);
            router.visit(row.item.url);
        }
    }

    /** Arrows walk the rows from anywhere in the palette; Enter opens the row only from the field, so buttons keep their own Enter. */
    function arrows(event: KeyboardEvent) {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        setActive((current) => (rows.length === 0 ? 0 : (current + step + rows.length) % rows.length));
    }

    function enter(event: KeyboardEvent) {
        if (event.key === 'Enter') {
            event.preventDefault();
            choose(rows[active]);
        }
    }

    return (
        <Dialog open={open} onOpenChange={change}>
            <DialogContent className={`${menuBoxClass} ${styles.palette}`} closeButton={false} aria-describedby={undefined} onKeyDown={arrows}>
                <DialogTitle className="sr-only">{t('Search')}</DialogTitle>
                <div className={styles.field}>
                    <input
                        type="search"
                        className={styles.input}
                        value={query}
                        placeholder={t('Search')}
                        aria-label={t('Search')}
                        aria-controls="palette-results"
                        aria-activedescendant={rows[active] ? `palette-row-${active}` : undefined}
                        onChange={(event) => setQuery(event.target.value)}
                        onKeyDown={enter}
                        autoFocus
                    />
                    <button type="button" className={styles.cancel} onClick={() => change(false)}>
                        {t('Cancel')}
                    </button>
                </div>
                <div id="palette-results" role="listbox" aria-label={t('Search')} className={styles.results}>
                    {shown.map((group) => (
                        <PaletteGroup key={group.group} group={group} rows={rows} active={active} onHover={setActive} onChoose={choose} />
                    ))}
                    {searched !== '' && trimmed === searched && shown.length === 0 && (
                        <DialogDescription className={styles.empty}>{t('No results for "{{query}}"', { query: searched })}</DialogDescription>
                    )}
                </div>
                <div className={styles.footer}>
                    <button type="button" className={styles.archived} aria-pressed={archived} onClick={() => setArchived((current) => !current)}>
                        {archived ? t('Hide archived') : t('Show archived too')}
                    </button>
                    <p className={styles.hints} aria-hidden="true">
                        <span>↑↓ {t('select')}</span>
                        <span>↵ {t('open')}</span>
                        <span>
                            <b>esc</b> {t('close')}
                        </span>
                    </p>
                </div>
            </DialogContent>
        </Dialog>
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
            <p className={menuHeadClass}>
                <span>{t(GROUP_LABEL[group.group])}</span>
                <span className={styles.count}>{group.total}</span>
            </p>
            {rows.map((row, index) =>
                row.group !== group.group ? null : (
                    <button
                        key={row.kind === 'item' ? `${row.group}-${row.item.id}` : `${row.group}-more`}
                        id={`palette-row-${index}`}
                        type="button"
                        role="option"
                        aria-selected={index === active}
                        data-highlighted={index === active ? '' : undefined}
                        className={`${menuRowClass} ${styles.row}`}
                        tabIndex={-1}
                        onMouseMove={() => onHover(index)}
                        onClick={() => onChoose(row)}
                    >
                        {row.kind === 'item' ? (
                            <>
                                <span className={menuLabelClass}>{itemTitle(row.group, row.item, t, i18n.language)}</span>
                                <span className={`${menuValueClass} ${styles.caption}`}>{itemCaption(row.group, row.item, t)}</span>
                            </>
                        ) : (
                            <>
                                <span className={`${menuLabelClass} ${styles.caption}`}>{t('Show more')} ↓</span>
                                <span className={`${menuValueClass} ${styles.caption}`}>{t('{{count}} more', { count: row.left })}</span>
                            </>
                        )}
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
