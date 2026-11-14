import { BottomSheet } from '@/components/solo/bottom-sheet';
import { type ClientTab } from '@/components/solo/client-header';
import { type SearchGroup } from '@/components/solo/command-palette';
import { SoloField, SoloInput } from '@/components/solo/field';
import { menuBoxClass, menuFillClass, menuHeadClass, menuLabelClass, menuRowClass } from '@/components/solo/menu';
import { GroupHeading } from '@/components/solo/tile';
import { useSoloWide } from '@/components/solo/use-solo-wide';
import { Icon } from '@/components/ui/icon';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { jsonRequest } from '@/lib/json-request';
import { search } from '@/routes';
import clients from '@/routes/clients';
import { Link } from '@inertiajs/react';
import { type ReactElement, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './client-switcher.module.css';

export interface SwitcherClient {
    id: number;
    name: string;
}

export interface SwitcherLists {
    pinned: SwitcherClient[];
    recent: SwitcherClient[];
}

/** Where a chosen client opens: the tab the switcher was opened on. */
export function switcherHref(clientId: number, tab: ClientTab): string {
    return clients.show({ client: clientId, tab }).url;
}

const MIN_QUERY = 2;

interface SwitcherProps {
    clientId: number;
    tab: ClientTab;
    /** The tab's name, for the line that says the tab stays. */
    tabLabel: string;
    trigger: ReactElement;
}

/**
 * Figma: the client switcher (1090:9227, phone sheet 1090:9466). Opened from the client token: a search, the pinned
 * clients, the ones opened last and the way to the whole list. A chosen client opens on the tab you are on.
 */
export function ClientSwitcher({ clientId, tab, tabLabel, trigger }: SwitcherProps) {
    const { t } = useTranslation();
    const wide = useSoloWide();
    const [open, setOpen] = useState(false);
    const body = open ? <SwitcherBody clientId={clientId} tab={tab} tabLabel={tabLabel} onChoose={() => setOpen(false)} /> : null;

    if (!wide) {
        return (
            <BottomSheet open={open} onOpenChange={setOpen} title={t('Switch client')} trigger={trigger}>
                {body}
            </BottomSheet>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent look="solo-menu" align="start" sideOffset={12} className={styles.panel} aria-label={t('Switch client')}>
                {body}
            </PopoverContent>
        </Popover>
    );
}

function useSwitcherLists(): SwitcherLists | null {
    const [lists, setLists] = useState<SwitcherLists | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        jsonRequest<SwitcherLists>(clients.switcher().url, 'GET', undefined, controller.signal)
            .then(setLists)
            .catch(() => undefined);

        return () => controller.abort();
    }, []);

    return lists;
}

function useClientSearch(query: string): SwitcherClient[] | null {
    const [found, setFound] = useState<{ query: string; clients: SwitcherClient[] } | null>(null);
    const trimmed = query.trim();

    useEffect(() => {
        if (trimmed.length < MIN_QUERY) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            jsonRequest<{ groups: SearchGroup[] }>(search.url({ query: { q: trimmed, group: 'clients' } }), 'GET', undefined, controller.signal)
                .then((result) =>
                    setFound({ query: trimmed, clients: (result.groups[0]?.items ?? []).map((item) => ({ id: item.id, name: item.title })) }),
                )
                .catch(() => undefined);
        }, 150);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [trimmed]);

    return trimmed.length >= MIN_QUERY && found?.query === trimmed ? found.clients : null;
}

function SwitcherBody({ clientId, tab, tabLabel, onChoose }: Omit<SwitcherProps, 'trigger'> & { onChoose: () => void }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const lists = useSwitcherLists();
    const found = useClientSearch(query);
    const searching = query.trim().length >= MIN_QUERY;

    return (
        <div className={[menuBoxClass, menuFillClass, styles.body].join(' ')}>
            <SoloField label={t('Client name')} htmlFor="client-switcher-search">
                <SoloInput
                    id="client-switcher-search"
                    type="search"
                    value={query}
                    placeholder={t('Search for a client')}
                    autoComplete="off"
                    autoFocus
                    onChange={(event) => setQuery(event.target.value)}
                />
            </SoloField>
            <SwitcherRows
                clientId={clientId}
                tab={tab}
                onChoose={onChoose}
                groups={
                    searching
                        ? [{ head: t('Clients'), clients: found ?? [] }]
                        : [
                              { head: t('Pinned clients'), clients: lists?.pinned ?? [] },
                              { head: t('Recent clients'), clients: lists?.recent ?? [] },
                          ]
                }
            />
            {searching && found?.length === 0 && <p className={styles.empty}>{t('No clients found.')}</p>}
            <p className={menuHeadClass}>{t('You stay on the {{tab}} tab', { tab: tabLabel })}</p>
            <Link href={clients.index()} className={menuRowClass} onClick={onChoose}>
                <span className={menuLabelClass}>{t('All clients list')}</span>
            </Link>
        </div>
    );
}

/** The switcher's groups of client rows; an empty group is left out and the open client carries the check. */
export function SwitcherRows({
    groups,
    clientId,
    tab,
    onChoose,
}: {
    groups: { head: string; clients: SwitcherClient[] }[];
    clientId: number;
    tab: ClientTab;
    onChoose?: () => void;
}) {
    return (
        <>
            {groups
                .filter((group) => group.clients.length > 0)
                .map((group) => (
                    <div key={group.head} role="group" aria-label={group.head} className={styles.group}>
                        <GroupHeading as="p" size="compact" flush label={group.head} />
                        {group.clients.map((client) => (
                            <Link
                                key={client.id}
                                href={switcherHref(client.id, tab)}
                                className={menuRowClass}
                                aria-current={client.id === clientId ? 'true' : undefined}
                                onClick={onChoose}
                            >
                                <span className={menuLabelClass}>{client.name}</span>
                                {client.id === clientId && <Icon name="check" size={16} />}
                            </Link>
                        ))}
                    </div>
                ))}
        </>
    );
}
