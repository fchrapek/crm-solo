import { type Place, type PlaceContext, type PlacePill } from '@/components/solo/shell/place';
import contactsRoute from '@/routes/contacts';
import leads from '@/routes/leads';

/** Kontakty and Leady are siblings under the Kontakty rail item. */
export function peoplePills(current: 'contacts' | 'leads', t: PlaceContext['t']): PlacePill[] {
    return [
        { label: t('Contacts'), href: contactsRoute.index().url, active: current === 'contacts' },
        { label: t('Leads'), href: leads.index().url, active: current === 'leads' },
    ];
}

export interface ContactFilters {
    search: string;
    trashed: 'with' | null;
}

/** The list's query string: the search, and the deleted contacts when they are shown. */
export const listQuery = ({ search, trashed }: ContactFilters) => ({ ...(search === '' ? {} : { search }), ...(trashed === null ? {} : { trashed }) });

export const listUrl = (filters: ContactFilters) => contactsRoute.index({ query: listQuery(filters) }).url;

/** Kontakty is a section's home: no tokens. The command bar narrows the list and keeps the deleted ones in view. */
export function contactsPlace({ filters }: { filters: ContactFilters }, { t }: PlaceContext): Place {
    return {
        pills: peoplePills('contacts', t),
        filter: { label: t('Search contacts'), href: (query) => listUrl({ ...filters, search: query }) },
    };
}
