import { hoursText } from '@/lib/hours-text';
import clients from '@/routes/clients';
import { type PlaceContext } from '../place';
import { type DogPose } from '../dog-still';

export type HelperWindowKind = 'plan' | 'report' | 'client';

export interface HelperHint {
    /** One line. */
    text: string;
    /** The label of the one action; it opens the helper's window. */
    action: string;
    /** A warning: the dog is surprised instead of looking at the line. */
    warning?: boolean;
}

export interface HelperLink {
    label: string;
    href: string;
}

export interface HelperScene {
    /** The example window the dog opens on this screen. */
    window: HelperWindowKind;
    hint: HelperHint | null;
    /** Places the "what is going on" window links to. */
    links: HelperLink[];
    /** A screen whose own content shows the dog large. */
    hidden?: boolean;
}

export interface HelperContext {
    t: PlaceContext['t'];
    locale: string;
}

type SceneResolver = (props: Record<string, unknown>, context: HelperContext) => Partial<HelperScene>;

const record = (value: unknown): Record<string, unknown> => (typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {});

const clientLinks = (id: number, { t }: HelperContext): HelperLink[] => [
    { label: t('Tasks'), href: clients.show({ client: id, tab: 'zadania' }).url },
    { label: t('Billing'), href: clients.show({ client: id, tab: 'rozliczenia' }).url },
    { label: t('Journal'), href: clients.show({ client: id, tab: 'dziennik' }).url },
];

/** One scene per screen, by Inertia page name. A page adds its line here; a page without one shows the dog alone. */
const SCENES: Record<string, SceneResolver> = {
    'today/index': (props, { t }) => {
        const free = Number(props.maxPicks ?? 0) - (Array.isArray(props.picks) ? props.picks.length : 0);

        return {
            window: 'plan',
            hint: props.state === 'paper' && free > 0 ? { text: t('{{count}} free slots left for today. Suggest tasks?', { count: free }), action: t('Suggest') } : null,
        };
    },
    'clients/index': (props, { t }) => ({
        window: 'report',
        hidden: Object.values(record(props.stage_counts)).every((count) => count === 0) && props.stage_counts !== undefined,
        hint: { text: t('helper_hint_clients'), action: t('Show the preview') },
    }),
    'clients/show': (props, context) => {
        const { t, locale } = context;
        const client = record(props.client);
        const name = String(client.short_name ?? '');
        const links = typeof client.id === 'number' ? clientLinks(client.id, context) : [];

        if (props.tab === 'rozliczenia') {
            return { window: 'report', links, hint: { text: t('helper_hint_billing'), action: t('Show the preview') } };
        }

        const remaining = record(record(props.overview).hours).remaining;

        if (typeof remaining === 'number' && remaining < 0) {
            return {
                window: 'report',
                links,
                hint: { text: t('helper_hint_client_over', { name, hours: hoursText(-remaining, locale) }), action: t('Show the preview'), warning: true },
            };
        }

        return { window: 'client', links, hint: { text: t('helper_hint_client', { name }), action: t('Show the preview') } };
    },
    'tasks/show': (_props, { t }) => ({ window: 'plan', hint: { text: t('helper_hint_task'), action: t('Show the preview') } }),
};

export function helperScene(page: string, props: Record<string, unknown>, context: HelperContext): HelperScene {
    return { window: 'plan', hint: null, links: [], ...SCENES[page]?.(props, context) };
}

export function helperHint(page: string, props: Record<string, unknown>, context: HelperContext): HelperHint | null {
    return helperScene(page, props, context).hint;
}

/** What the closed dog does: surprised at a warning, looking at its line, or the default with the sunglasses. */
export function helperPose(hint: HelperHint | null): DogPose {
    return hint === null ? 'cool' : hint.warning ? 'wow' : 'look-left';
}

export interface HelperState {
    shown: HelperWindowKind | null;
    /** The page whose line was already used or dismissed. */
    seen: string | null;
}

export const HELPER_CLOSED: HelperState = { shown: null, seen: null };

/** Opening a window, switching to another and closing all use up the page's line: it shows once per visit. */
export function helperShow(_state: HelperState, action: { page: string; show: HelperWindowKind | null }): HelperState {
    return { shown: action.show, seen: action.page };
}
