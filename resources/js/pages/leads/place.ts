import { type Place, type PlaceContext, type PlacePill } from '@/components/solo/shell/place';
import leads from '@/routes/leads';
import { peoplePills } from '../contacts/place';

export interface LeadFilters {
    search?: string;
    source?: string;
    trashed?: string;
    pipeline: string;
    stage: string;
    tier: string;
}

export type LeadView = 'kanban' | 'list';

/** The view lives in the URL beside the filters, so a reload and a link keep it. */
export function leadView(url: string): LeadView {
    return new URLSearchParams(url.split('?')[1] ?? '').get('widok') === 'lista' ? 'list' : 'kanban';
}

/** The list's URL: only what narrows it, so the plain board is /leads. */
export function leadsUrl(filters: Partial<LeadFilters>, view: LeadView): string {
    const query = Object.fromEntries(
        Object.entries({ ...filters, widok: view === 'list' ? 'lista' : undefined }).filter(([, value]) => value !== undefined && value !== '' && value !== 'all'),
    );

    return leads.index({ query }).url;
}

/** Leady stand beside Kontakty; the command bar narrows the list and keeps the filters and the view. */
export function leadsPlace({ filters }: { filters: LeadFilters }, { t, url }: PlaceContext): Place {
    return {
        tokens: [{ label: t('Leads') }],
        pills: peoplePills('leads', t),
        filter: { label: t('Search leads'), href: (query) => leadsUrl({ ...filters, search: query }, leadView(url)) },
    };
}

/** One level down: Leady, then the lead or the new one. */
export function leadPlace(label: string, { t }: PlaceContext): Place {
    return { tokens: [{ label: t('Leads'), href: leads.index().url }, { label }], pills: peoplePills('leads', t) };
}

interface Choice {
    value: string;
    label: string;
}

/** One filter as a menu pill: its first choice is "all" and does not narrow. */
export function filterPill(head: string, current: string | undefined, choices: Choice[], href: (value: string) => string): PlacePill {
    const active = choices.find((choice) => choice.value === (current ?? 'all')) ?? choices[0];

    return {
        label: active === choices[0] ? head : `${head}: ${active.label.toLocaleLowerCase()}`,
        active: active !== choices[0],
        menu: choices.map((choice) => ({ label: choice.label, href: href(choice.value), active: choice === active })),
    };
}
