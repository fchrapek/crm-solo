import type { InertiaLinkProps } from '@inertiajs/react';
import type { ComponentType, ReactElement, ReactNode } from 'react';

type Href = NonNullable<InertiaLinkProps['href']>;

/** One level of where the page is, below its section. The last token is the page itself and needs no href. */
export interface PlaceToken {
    label: string;
    href?: Href;
    /** The token opens a switcher between its siblings instead of leading up; it is given the token as its trigger. */
    switcher?: (trigger: ReactElement) => ReactNode;
}

/** A sibling of the last level: a tab, a month, a filter, a day view. */
export interface PlaceLinkPill {
    label: string;
    href: Href;
    active?: boolean;
    count?: number;
    /** A word after the label in the count's face, such as "in progress" on the month in progress. */
    note?: string;
    /** The place is finished (a closed day): a check after the label, read out as this text. */
    done?: string;
    /** How the visit goes, for a pill that filters one block of the page: a partial reload that keeps the scroll. */
    visit?: Pick<InertiaLinkProps, 'only' | 'preserveScroll' | 'preserveState' | 'replace'>;
    /** In a menu: the group the choice sits under, such as its year; neighbours with the same group share one heading. */
    group?: string;
}

/** A pill that opens a menu of choices, such as one more filter beside the status pills; active once a choice narrows the page. */
export interface PlaceMenuPill {
    label: string;
    active?: boolean;
    /** The menu's title above its choices. */
    heading?: string;
    menu: PlaceLinkPill[];
}

export type PlacePill = PlaceLinkPill | PlaceMenuPill;

/** A page whose list the command bar can narrow: the first result row asks the page for the typed words. */
export interface PlaceFilter {
    label: string;
    href: (query: string) => string;
}

export interface Place {
    tokens?: PlaceToken[];
    pills?: PlacePill[];
    /** wide: the pills stand from 768 px only, where a phone shows the same places as the page's own list. */
    pillsFrom?: 'wide';
    filter?: PlaceFilter;
}

export interface PlaceContext {
    t: (key: string, options?: Record<string, unknown>) => string;
    /** The current URL with its query string. */
    url: string;
    locale: string;
}

/** A page states its place as a function of its props: `Page.place = (props, { t }) => ({ tokens, pills })`. */
export type PlaceResolver<P = Record<string, unknown>> = (props: P, context: PlaceContext) => Place;

export type PageWithPlace = ComponentType & { place?: PlaceResolver<never> };
