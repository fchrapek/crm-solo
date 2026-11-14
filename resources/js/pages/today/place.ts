import { type PlaceContext, type PlaceLinkPill } from '@/components/solo/shell/place';
import { dashboard } from '@/routes';
import today from '@/routes/today';

export type DayStep = 'today' | 'done' | 'tomorrow' | 'week';

/** The day views as pills: every one is a link, and only the close button writes. A closed day ticks Podsumowanie. */
export function dayPills(current: DayStep | null, t: PlaceContext['t'], closed = false): PlaceLinkPill[] {
    const steps = [
        { key: 'today', label: t('Today'), href: dashboard({ query: { widok: 'dzis' } }) },
        { key: 'done', label: t('Summary'), href: dashboard({ query: { widok: 'gotowe' } }) },
        { key: 'tomorrow', label: t('Tomorrow'), href: today.plan() },
        { key: 'week', label: t('Week'), href: today.week() },
    ] as const;

    return steps.map(({ key, label, href }) => ({ label, href, active: key === current, ...(key === 'done' && closed ? { done: t('Day closed') } : {}) }));
}
