import { CLIENT_TAB_LABEL, CLIENT_TABS, type ClientHeaderData, type ClientTab } from '@/components/solo/client-header';
import { type Place, type PlaceContext, type PlaceLinkPill, type PlacePill, type PlaceToken } from '@/components/solo/shell/place';
import { capitalize, monthName } from '@/lib/dates';
import clients from '@/routes/clients';
import projectBoard from '@/routes/project-board';
import {
    type CooperationCounts,
    cooperationOptions,
    DESKTOP_STAGES,
    type ListCard,
    listQuery,
    type ListQuery,
    type ListStage,
    STAGE_LABEL,
} from './components/client-list/filters';
import { ClientSwitcher } from './components/client-switcher';

type T = PlaceContext['t'];

export interface ClientListPlaceProps {
    filters: ListQuery;
    stage_counts: Record<ListStage, number>;
    cooperation_counts: CooperationCounts;
    card: ListCard | null;
}

/** The list's query with an account's own field filters folded in. */
export function listFilters({ filters, card }: Pick<ClientListPlaceProps, 'filters' | 'card'>): ListQuery {
    return card === null || card === undefined ? filters : { ...filters, assignee: card.filters.assignee, f: card.filters.f };
}

const listUrl = (query: ListQuery) => clients.index({ query: listQuery(query) }).url;

function menuPill(head: string, choices: PlaceLinkPill[]): PlacePill {
    const current = choices.find((choice) => choice.active);
    const narrowed = current !== undefined && current !== choices[0];

    return { label: `${head}: ${(current ?? choices[0]).label.toLocaleLowerCase()}`, active: narrowed, menu: choices };
}

function assigneeLabel(value: ListCard['assignees'][number], t: T): string {
    return value.value === 'me'
        ? t('My clients')
        : value.value === null
          ? t('All clients')
          : value.value === 'none'
            ? t('No assignee')
            : (value.label ?? '');
}

/** Clients: the statuses as pills with their counts, then one menu pill per further filter. A page change starts over at the first page. */
/** An account that has no client yet: the list gives way to the first step. */
export function noClients(counts: Record<string, number> | undefined): boolean {
    return counts !== undefined && Object.values(counts).every((count) => count === 0);
}

export function clientListPlace(props: ClientListPlaceProps, { t }: PlaceContext): Place {
    const filters = listFilters(props);
    const { card } = props;
    const facets = filters.f ?? {};

    if (noClients(props.stage_counts)) return {};

    return {
        pills: [
            ...DESKTOP_STAGES.map((stage) => ({
                label: t(STAGE_LABEL[stage]),
                href: listUrl({ ...filters, stage }),
                active: filters.stage === stage,
                count: props.stage_counts[stage],
            })),
            ...(card === null
                ? []
                : [
                      menuPill(
                          t('Assignee'),
                          card.assignees.map((option) => ({
                              label: assigneeLabel(option, t),
                              href: listUrl({ ...filters, assignee: option.value }),
                              active: (filters.assignee ?? null) === option.value,
                              count: option.count,
                          })),
                      ),
                      ...card.facets.map((facet) =>
                          menuPill(
                              facet.label,
                              facet.options.map((option) => {
                                  const next = { ...facets };
                                  if (option.value === null) delete next[facet.key];
                                  else next[facet.key] = option.value;

                                  return {
                                      label: option.value === null ? t('All') : (option.label ?? option.value),
                                      href: listUrl({ ...filters, f: next }),
                                      active: (facets[facet.key] ?? null) === option.value,
                                      count: option.count,
                                  };
                              }),
                          ),
                      ),
                  ]),
            ...(card === null || card.billing
                ? [
                      menuPill(
                          t('Cooperation'),
                          cooperationOptions(props.cooperation_counts, filters.cooperation_type).map((option) => ({
                              label: t(option.label),
                              href: listUrl({ ...filters, cooperation_type: option.value }),
                              active: option.active,
                              count: option.count,
                          })),
                      ),
                  ]
                : []),
        ],
        filter: { label: t('Filter the client list'), href: (query) => listUrl({ ...filters, search: query }) },
    };
}

type PlaceClient = Pick<ClientHeaderData, 'id' | 'short_name'>;

const tabUrl = (clientId: number, tab: ClientTab) => clients.show({ client: clientId, tab }).url;

/** The client level of a place: it leads to the record's first tab and opens the switcher, which keeps the tab. */
export function clientToken(client: PlaceClient, tab: ClientTab, t: T): PlaceToken {
    return {
        label: client.short_name,
        href: tabUrl(client.id, 'overview'),
        switcher: (trigger) => <ClientSwitcher clientId={client.id} tab={tab} tabLabel={t(CLIENT_TAB_LABEL[tab])} trigger={trigger} />,
    };
}

export interface ClientPlaceProps {
    client: ClientHeaderData;
    tab: ClientTab;
    /** Billing of a retainer client: the month the screen shows. */
    billing_month?: { period: string };
    /** Journal's sub-view: `relationship` adds it as a third level. */
    journal_view?: string | null;
}

/**
 * A client tab: the client, then the tab; the tabs are the pills. Billing adds the month it shows as a third level,
 * and its tab then leads back to the month the client owes next. An account with billing off has no Billing.
 */
export function clientPlace({ client, tab, billing_month: month, journal_view: view }: ClientPlaceProps, { t, locale }: PlaceContext): Place {
    const shown = tab === 'billing' && month !== undefined ? month.period : null;

    return {
        tokens:
            tab === 'journal' && view === 'relationship'
                ? [clientToken(client, tab, t), { label: t(CLIENT_TAB_LABEL[tab]), href: tabUrl(client.id, tab) }, { label: t('Relationship') }]
                : shown !== null
                  ? [
                        clientToken(client, tab, t),
                        { label: t(CLIENT_TAB_LABEL[tab]), href: tabUrl(client.id, tab) },
                        { label: capitalize(monthName(shown, locale)) },
                    ]
                  : [clientToken(client, tab, t), { label: t(CLIENT_TAB_LABEL[tab]) }],
        pills: CLIENT_TABS.filter((key) => key !== 'billing' || client.billing !== false).map((key) => ({
            label: t(CLIENT_TAB_LABEL[key]),
            href: tabUrl(client.id, key),
            active: key === tab,
            ...(key === 'tasks' && client.open_tasks_count > 0 ? { count: client.open_tasks_count } : {}),
        })),
    };
}

export interface ProjectPlaceProps {
    client: PlaceClient;
    project: { id: number; name: string };
    /** The client's projects with their open tasks, and the open tasks of the whole client. */
    projects: { id: number; name: string; open: number }[];
    open_tasks_count: number;
}

/** A project page: the client, Tasks, the project; the pills are the client's projects, and All leads back to Tasks. */
export function projectPlace({ client, project, projects, open_tasks_count }: ProjectPlaceProps, { t }: PlaceContext): Place {
    return {
        tokens: [
            clientToken(client, 'tasks', t),
            { label: t(CLIENT_TAB_LABEL.tasks), href: tabUrl(client.id, 'tasks') },
            { label: project.name },
        ],
        pills: [
            { label: t('All'), href: tabUrl(client.id, 'tasks'), count: open_tasks_count },
            ...projects.map((row) => ({
                label: row.name,
                href: projectBoard.show({ client: client.id, project: row.id }).url,
                active: row.id === project.id,
                count: row.open,
            })),
        ],
    };
}
