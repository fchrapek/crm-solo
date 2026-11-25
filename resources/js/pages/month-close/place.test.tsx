import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../../lang/pl.json';
import Index, { closeListUrl, type CloseRow, filterOf, filterPills, matchesFilter } from './index';
import Show, { settlementChip, stepLink } from './show';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });
const t = i18next.t.bind(i18next);
const context = { t: t as (key: string, options?: Record<string, unknown>) => string, url: '/month-close?period=2026-09', locale: 'pl' };

const row = (id: number, extra: Partial<CloseRow> = {}): CloseRow => ({
    client: { id, name: `Klient ${id}` },
    total: 9,
    resolved: 3,
    next: { key: 'wp_updates', owner: 'agent' },
    sites: 1,
    invoice: 'pending',
    settlement: null,
    finished_on: null,
    ...extra,
});

const ready = { state: 'ready' as const, period: '2026-09', active_period: '2026-09', closed_on: null, closing: 2 };

const rows = [
    row(1),
    row(2, { next: { key: 'live_deploy', owner: 'you' } }),
    row(3, { next: null, resolved: 9, settlement: ready }),
    row(4, { next: null, resolved: 9, settlement: { ...ready, state: 'blocked', active_period: '2026-08' } }),
    row(5, { next: null, resolved: 9, finished_on: '2026-10-03' }),
];

describe('the place of the month close', () => {
    test('the list is Month close and its month, with the months around it as pills', () => {
        const place = Index.place({ period: '2026-09', months: ['2026-08', '2026-09', '2026-10'] } as never, context);

        expect(place.tokens.map((token) => [token.label, 'href' in token ? token.href : undefined])).toEqual([
            ['Zamknięcie miesiąca', '/month-close'],
            ['Wrzesień', undefined],
        ]);
        expect(place.pills.map((pill) => [pill.label, pill.href, pill.active])).toEqual([
            ['Sierpień', '/month-close?period=2026-08', false],
            ['Wrzesień', '/month-close?period=2026-09', true],
            ['Październik', '/month-close?period=2026-10', false],
        ]);
    });

    test("a client's card adds the client, and its pills are the month's clients with their progress", () => {
        const props = {
            period: '2026-09',
            client: { id: 2, short_name: 'Fundacja Testowa' },
            clients: [
                { id: 1, name: 'Pracownia Mebli', resolved: 0, total: 15 },
                { id: 2, name: 'Fundacja Testowa', resolved: 6, total: 9 },
                { id: 3, name: 'Kawiarnia', resolved: 0, total: 0 },
            ],
        };
        const place = Show.place(props as never, context);

        expect(place.tokens.map((token) => [token.label, 'href' in token ? token.href : undefined])).toEqual([
            ['Zamknięcie miesiąca', '/month-close'],
            ['Wrzesień', '/month-close?period=2026-09'],
            ['Fundacja Testowa', undefined],
        ]);
        expect(place.pills.map((pill) => [pill.label, pill.href, pill.active ?? false, pill.note ?? pill.count])).toEqual([
            ['Pracownia Mebli', '/month-close/1?period=2026-09', false, '0 z 15'],
            ['Fundacja Testowa', '/month-close/2?period=2026-09', true, '6 z 9'],
            ['Wszyscy', '/month-close?period=2026-09', false, 3],
        ]);
    });
});

describe('the filters of the list', () => {
    test('each filter keeps the rows it names', () => {
        const ids = (filter: Parameters<typeof matchesFilter>[1]) => rows.filter((candidate) => matchesFilter(candidate, filter)).map((candidate) => candidate.client.id);

        expect(ids('all')).toEqual([1, 2, 3, 4, 5]);
        expect(ids('you')).toEqual([2]);
        expect(ids('agent')).toEqual([1]);
        expect(ids('hours')).toEqual([3, 4]);
        expect(ids('finished')).toEqual([5]);
    });

    test('the filter travels in the URL, and anything else shows everyone', () => {
        expect(filterOf(closeListUrl('2026-09', 'you'))).toBe('you');
        expect(closeListUrl('2026-09', 'hours')).toBe('/month-close?period=2026-09&filter=hours');
        expect(closeListUrl('2026-09')).toBe('/month-close?period=2026-09');
        expect(filterOf('/month-close?filter=anything')).toBe('all');
    });

    test('the pills carry their counts and keep the page where it is', () => {
        const pills = filterPills(rows, '2026-09', 'you', t);

        expect(pills.map((pill) => [pill.label, pill.count, pill.active])).toEqual([
            ['Wszyscy', 5, false],
            ['Czeka na Ciebie', 1, true],
            ['Agent', 1, false],
            ['Godziny do zamknięcia', 2, false],
            ['Zakończone', 1, false],
        ]);
        expect(pills[1].visit).toMatchObject({ preserveState: true, preserveScroll: true });
    });
});

describe('the card of a client', () => {
    test('the report and the invoice step lead to the month on Billing; other steps lead nowhere', () => {
        expect(stepLink({ key: 'report' }, 7, '2026-09', t)).toEqual({ label: 'Raport', href: '/clients/7/billing?month=2026-09' });
        expect(stepLink({ key: 'draft_invoice' }, 7, '2026-09', t)).toEqual({ label: 'Faktury', href: '/clients/7/billing?month=2026-09' });
        expect(stepLink({ key: 'wp_updates' }, 7, '2026-09', t)).toBeNull();
        expect(stepLink({ key: 'summary_email' }, 7, '2026-09', t)).toBeNull();
    });

    test('the hours chip says whether the month waits, is closed, or is held by an earlier one', () => {
        expect(settlementChip(ready, 'pl', t)).toEqual({ status: 'open', label: 'do zamknięcia' });
        expect(settlementChip({ ...ready, state: 'closed', closed_on: '2026-10-05' }, 'pl', t)).toEqual({ status: 'done', label: 'zamknięty 05.10' });
        expect(settlementChip({ ...ready, state: 'blocked', active_period: '2026-08' }, 'pl', t)).toEqual({ status: 'waiting', label: 'najpierw zamknij sierpień' });
    });
});
