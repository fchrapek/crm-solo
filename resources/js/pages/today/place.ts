import { type PlaceContext, type PlaceLinkPill, type PlaceToken } from '@/components/solo/shell/place';
import { parseDay, shortDate } from '@/lib/dates';
import { dashboard } from '@/routes';
import today from '@/routes/today';

export type DayStep = 'today' | 'done' | 'tomorrow' | 'week';

/** The day views as pills: every one is a link, and only the close button writes. A closed day ticks Summary. */
export function dayPills(current: DayStep | null, t: PlaceContext['t'], closed = false): PlaceLinkPill[] {
    const steps = [
        { key: 'today', label: t('Today'), href: dashboard({ query: { view: 'today' } }) },
        { key: 'done', label: t('Summary'), href: dashboard({ query: { view: 'summary' } }) },
        { key: 'tomorrow', label: t('Tomorrow'), href: today.plan() },
        { key: 'week', label: t('Week'), href: today.week() },
    ] as const;

    return steps.map(({ key, label, href }) => ({ label, href, active: key === current, ...(key === 'done' && closed ? { done: t('Day closed') } : {}) }));
}

/** A day opened by its date, with the days beside it. */
export interface DayNav {
    date: string;
    /** Null before the first day the account has anything on. */
    prev: string | null;
    next: string;
    today: string;
}

/** Where a day's page leads: today is the home screen. */
export const dayHref = (date: string, todayDate: string) => (date === todayDate ? dashboard() : today.day(date));

/** "pon. 16.11". */
export function dayLabel(date: string, locale: string): string {
    return `${new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(parseDay(date))} ${shortDate(date, locale)}`;
}

/** Figma: the day page (1165:11406). The day as the place, and the days before and after it, then today, as the pills. */
export function dayPlace(nav: DayNav, { t, locale }: Pick<PlaceContext, 't' | 'locale'>): { tokens: PlaceToken[]; pills: PlaceLinkPill[] } {
    const label = dayLabel(nav.date, locale);

    return {
        tokens: [{ label: t('Day'), href: dashboard() }, { label }],
        pills: [
            ...(nav.prev === null ? [] : [{ label: `← ${dayLabel(nav.prev, locale)}`, href: dayHref(nav.prev, nav.today) }]),
            { label, href: today.day(nav.date), active: true },
            { label: `${dayLabel(nav.next, locale)} →`, href: dayHref(nav.next, nav.today) },
            { label: t('Today'), href: dashboard() },
        ],
    };
}
