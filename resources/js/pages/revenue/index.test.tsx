import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../../lang/pl.json';
import Index, { metricOf, periodPills, revenueUrl, trendBars, trendText } from './index';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });
const t = i18next.t.bind(i18next) as (key: string, options?: Record<string, unknown>) => string;

const filters = { period: 'last_12_months', basis: 'accrual', from: '2025-11-01', to: '2026-10-31' };

describe('the place of Przychody', () => {
    test('the tokens are Przychody and the period, and the periods are the pills', () => {
        const place = Index.place({ filters } as never, { t, url: '/revenue?period=last_12_months', locale: 'pl' });

        expect(place.tokens.map((token) => [token.label, 'href' in token ? token.href : undefined])).toEqual([
            ['Przychody', '/revenue'],
            ['Ostatnie 12 miesięcy', undefined],
        ]);
        expect(place.pills.map((pill) => [pill.label, pill.active])).toEqual([
            ['Bieżący miesiąc', false],
            ['Bieżący kwartał', false],
            ['Od początku roku', false],
            ['Ostatnie 12 miesięcy', true],
        ]);
    });

    test('a period pill keeps the basis and the amounts the page is on', () => {
        const pills = periodPills({ period: 'ytd', basis: 'cash' }, 'gross', t);

        expect(pills[0].href).toBe('/revenue?period=this_month&basis=cash&kwoty=brutto');
        expect(periodPills({ period: 'ytd', basis: 'accrual' }, 'net', t)[0].href).toBe('/revenue?period=this_month');
    });
});

describe('the amounts of the page', () => {
    test('net unless the URL asks for gross', () => {
        expect(metricOf('/revenue')).toBe('net');
        expect(metricOf(revenueUrl(filters, 'gross'))).toBe('gross');
        expect(metricOf('/revenue?kwoty=netto')).toBe('net');
    });

    test('a change against the period before reads with its sign, and nothing to compare with reads as nothing', () => {
        expect(trendText(12.4, 'pl')?.replace(' ', ' ')).toBe('+12 %');
        expect(trendText(-9.6, 'pl')?.replace(' ', ' ')).toBe('−10 %');
        expect(trendText(0, 'pl')?.replace(' ', ' ')).toBe('0 %');
        expect(trendText(null, 'pl')).toBeNull();
    });

    test('each month is as tall as its share of the best month, in the chosen amounts', () => {
        const trend = [
            { month: '2026-08', net: 5000, gross: 6150 },
            { month: '2026-09', net: 10000, gross: 12300 },
        ];

        expect(trendBars(trend, 'net')).toEqual([
            { month: '2026-08', value: 5000, share: 0.5 },
            { month: '2026-09', value: 10000, share: 1 },
        ]);
        expect(trendBars(trend, 'gross')[1].value).toBe(12300);
        expect(trendBars([{ month: '2026-08', net: 0, gross: 0 }], 'net')[0].share).toBe(0);
    });
});
