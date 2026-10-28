export function projectHours(minutes: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(minutes / 60);
}

/** The phone's pushed Relacja z klientem screen is client state in the query string, so the back button closes it. */
export const RELATIONSHIP_PARAM = 'relacja';

export function isRelationshipScreen(url: string): boolean {
    const query = url.split('#')[0].split('?')[1];

    return query !== undefined && new URLSearchParams(query).has(RELATIONSHIP_PARAM);
}

export function withRelationshipScreen(url: string, open: boolean): string {
    const [path, query = ''] = url.split('#')[0].split('?');
    const params = new URLSearchParams(query);
    if (open) params.set(RELATIONSHIP_PARAM, '1');
    else params.delete(RELATIONSHIP_PARAM);
    const next = params.toString();

    return next === '' ? path : `${path}?${next}`;
}
