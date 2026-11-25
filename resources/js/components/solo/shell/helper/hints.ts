import { hoursText } from '@/lib/hours-text';
import { type CloseRow, matchesFilter } from '@/pages/month-close/filters';
import { type PlaceContext } from '../place';
import { type DogPose } from '../dog-still';
import { record } from './topics';

export interface HelperHint {
    /** One line, computed from the page's own props. */
    text: string;
    /** The topic the line leads to; its question is the line's link. */
    topic: string;
    /** A warning: the dog is surprised instead of looking at the line. */
    warning?: boolean;
}

export interface HelperScene {
    hint: HelperHint | null;
    /** A screen whose own content shows the dog large. */
    hidden?: boolean;
}

export interface HelperContext {
    t: PlaceContext['t'];
    locale: string;
}

type SceneResolver = (props: Record<string, unknown>, context: HelperContext) => Partial<HelperScene>;

/** One scene per screen, by Inertia page name: a line only where the page's data has a fact worth saying. */
const SCENES: Record<string, SceneResolver> = {
    'today/index': (props, { t }) => {
        const free = Number(props.maxPicks ?? 0) - (Array.isArray(props.picks) ? props.picks.length : 0);

        return { hint: props.state === 'paper' && free > 0 ? { text: t('{{count}} free slots left for today.', { count: free }), topic: 'today-picks' } : null };
    },
    'clients/index': (props) => ({
        hidden: props.stage_counts !== undefined && Object.values(record(props.stage_counts)).every((count) => count === 0),
    }),
    'clients/show': (props, { t, locale }) => {
        const remaining = record(record(props.overview).hours).remaining;

        return typeof remaining === 'number' && remaining < 0
            ? {
                  hint: {
                      text: t('helper_hint_client_over', { name: String(record(props.client).short_name ?? ''), hours: hoursText(-remaining, locale) }),
                      topic: 'hours-left',
                      warning: true,
                  },
              }
            : {};
    },
    'month-close/index': (props, { t }) => {
        const waiting = (Array.isArray(props.clients) ? (props.clients as CloseRow[]) : []).filter((row) => matchesFilter(row, 'you')).length;

        return { hint: waiting > 0 ? { text: t('{{count}} clients are waiting for you.', { count: waiting }), topic: 'month-close-who' } : null };
    },
};

export function helperScene(page: string, props: Record<string, unknown>, context: HelperContext): HelperScene {
    return { hint: null, ...SCENES[page]?.(props, context) };
}

/** What the closed dog does: surprised at a warning, looking at its line while the line shows, otherwise the sunglasses (it follows the pointer). */
export function helperPose(hint: HelperHint | null, lineShown: boolean): DogPose {
    return hint === null ? 'cool' : hint.warning ? 'wow' : lineShown ? 'look-left' : 'cool';
}

export interface HelperState {
    /** The page URL the state belongs to; on another URL the helper starts closed, its line unused. */
    url: string | null;
    open: boolean;
    /** The answer shown, or null for the list of topics. */
    topic: string | null;
}

export const HELPER_CLOSED: HelperState = { url: null, open: false, topic: null };

/** The state on this URL: anything kept from another page is dropped, so a visit starts with the dog closed. */
export function helperHere(state: HelperState, url: string): HelperState {
    return state.url === url ? state : HELPER_CLOSED;
}

/** Opening uses up the page's line: it shows once per visit, until the dog is first opened. */
export function hintShown(state: HelperState, url: string): boolean {
    return helperHere(state, url).url === null;
}
