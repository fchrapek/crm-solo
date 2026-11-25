import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { QueueHead, queuePlace } from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/sms', locale: 'pl-PL' };
const list = { data: [], links: [], total: 4 };

describe('the SMS queue place', () => {
    test('its token is SMS and the statuses are pills that keep the client and the search', () => {
        const place = queuePlace({ filters: { status: 'failed', search: 'anna', client: 7 }, messages: list }, context);

        expect(place.tokens).toEqual([{ label: 'SMS' }]);
        expect(place.pills?.map((pill) => pill.label)).toEqual(['Wszystkie statusy', 'Oczekujące', 'Wysłane', 'Nieudane', 'Niewysłane']);
        expect(place.pills?.filter((pill) => pill.active)).toEqual([{ label: 'Nieudane', href: '/sms?status=failed&search=anna&client=7', active: true, count: 4 }]);
        expect(place.pills?.[0]).toMatchObject({ href: '/sms?search=anna&client=7', active: false });
    });

    test('the command bar narrows the queue by client or text within the chosen status', () => {
        const place = queuePlace({ filters: { status: 'pending', search: '', client: null }, messages: list }, context);

        expect(place.filter?.href('wizja')).toBe('/sms?status=pending&search=wizja');
    });
});

describe('the SMS queue head', () => {
    test('the owner gets the way to the SMS settings, a member who cannot open Settings gets none', () => {
        const owner = renderToStaticMarkup(<QueueHead owner />);
        const member = renderToStaticMarkup(<QueueHead owner={false} />);

        expect(owner).toContain('href="/settings/messages"');
        expect(owner).toContain('Ustawienia SMS');
        expect(member).not.toContain('/settings');
        expect(member).toContain('SMS');
    });
});
