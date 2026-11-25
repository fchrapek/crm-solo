import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';
import pl from '../../../../lang/pl.json';
import { MonthCalendar, monthDays, shiftMonth } from './month-card';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const cells = (html: string) => [...html.matchAll(/<button type="button" class="[^"]*"([^>]*)>(\d+)<\/button>/g)].map(([, attrs, day]) => ({ day: Number(day), attrs }));

describe('MonthCalendar in pick mode', () => {
    test('a month has its days with Monday as the first weekday, and turns over a year end', () => {
        const november = monthDays('2026-11');

        expect(november).toHaveLength(30);
        expect(november[0]).toEqual({ date: '2026-11-01', weekday: 7 });
        expect(monthDays('2028-02')).toHaveLength(29);
        expect([shiftMonth('2026-12', 1), shiftMonth('2026-01', -1)]).toEqual(['2027-01', '2025-12']);
    });

    test('the chosen day and today are marked alike, weekdays ahead are filled and weekends bare', () => {
        const html = renderToStaticMarkup(<MonthCalendar pick={{ value: '2026-11-19', onPick: () => {}, today: '2026-11-17' }} />);
        const days = cells(html);
        const state = (day: number) => days.find((cell) => cell.day === day)?.attrs.match(/data-state="(\w+)"/)?.[1];

        expect(html).toContain('Listopad 2026');
        expect([state(17), state(19), state(20), state(21)]).toEqual(['today', 'today', 'future', undefined]);
        expect(days.find((cell) => cell.day === 19)?.attrs).toContain('aria-pressed="true"');
        expect(days.some((cell) => cell.attrs.includes('disabled'))).toBe(false);
    });

    test('with a first day, earlier days cannot be chosen and the month cannot go back past it', () => {
        const html = renderToStaticMarkup(<MonthCalendar pick={{ value: null, onPick: () => {}, min: '2026-11-17', today: '2026-11-17' }} />);
        const days = cells(html);

        expect(days.filter((cell) => cell.attrs.includes('disabled')).map((cell) => cell.day)).toEqual(Array.from({ length: 16 }, (_, i) => i + 1));
        expect(html).toMatch(/<button[^>]*aria-label="Poprzedni miesiąc"[^>]*disabled=""/);
        expect(html).not.toMatch(/<button[^>]*aria-label="Następny miesiąc"[^>]*disabled=""/);
    });
});
