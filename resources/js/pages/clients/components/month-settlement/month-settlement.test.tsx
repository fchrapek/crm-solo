import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import { monthMark } from '@/lib/dates';
import pl from '../../../../../../lang/pl.json';
import { CloseMonthSummary } from './close-month-dialog';
import type { ClosePreview } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const preview = (extra: Partial<ClosePreview> = {}): ClosePreview => ({
    client: 'Pracownia Mebli Przykładowa',
    period: '2026-09',
    next: '2026-10',
    contracted: 30,
    opening: -9.5,
    cap: 60,
    forfeited: 0,
    used: 32,
    closing: -11.5,
    source: 'report',
    report_status: 'finalized',
    entries: 36.5,
    hash: 'a'.repeat(40),
    refusal: null,
    ...extra,
});

const textOf = (p: ClosePreview) =>
    renderToStaticMarkup(<CloseMonthSummary preview={p} locale="pl-PL" />)
        .replace(/<[^>]+>/g, ' ')
        .replace(/[\u00a0\u202f]/g, ' ')
        .replace(/\s+/g, ' ');

describe('CloseMonthSummary', () => {
    test('the frame figures, the report as the source and the live entries as information', () => {
        const text = textOf(preview());

        expect(text).toContain('Pakiet +30,0 h');
        expect(text).toContain('Z poprzednich miesięcy −9,5 h');
        expect(text).toContain('Przepracowane · z raportu −32,0 h');
        expect(text).toContain('Bilans → otwarcie października −11,5 h');
        expect(text).toContain('Wpisy dziś: 36,5 h.');
        expect(text).toContain('Możesz otworzyć wrzesień ponownie w Rozliczeniach.');
    });

    test('a draft report says so, and entries matching the report add no info line', () => {
        const text = textOf(preview({ report_status: 'draft', entries: 32 }));

        expect(text).toContain('Przepracowane · z raportu (szkic) −32,0 h');
        expect(text).not.toContain('Wpisy dziś');
    });

    test('without a report the hours come from the entries and the entries line never repeats them', () => {
        const text = textOf(preview({ source: 'entries', report_status: null, used: 36.5, entries: 36.5, closing: -16 }));

        expect(text).toContain('Przepracowane · z wpisów −36,5 h');
        expect(text).not.toContain('Wpisy dziś');
    });

    test('hours over the rollover cap show as their own row', () => {
        expect(textOf(preview({ forfeited: 4 }))).toContain('Ponad limit kumulacji −4,0 h');
    });

    test('a refusal replaces the info line and is announced', () => {
        const html = renderToStaticMarkup(
            <CloseMonthSummary preview={preview({ refusal: 'Najpierw zatrzymaj timer z września 2026.' })} locale="pl-PL" />,
        );

        expect(html).toContain('role="alert"');
        expect(html).toContain('Najpierw zatrzymaj timer z września 2026.');
        expect(html).not.toContain('Możesz otworzyć');
    });
});

describe('close dialog copy', () => {
    test('the lead names the client and the month, colon included', () => {
        expect(i18next.t('close_month_lead', { client: 'Pracownia Mebli Przykładowa', month: 'wrzesień' })).toBe(
            'Pracownia Mebli Przykładowa. Zamrażamy wrzesień w banku godzin:',
        );
    });

    test('the reopen lead says the month becomes active again', () => {
        expect(i18next.t('reopen_month_lead', { client: 'Pracownia Mebli Przykładowa', Month: 'Wrzesień' })).toBe(
            'Pracownia Mebli Przykładowa. Wrzesień znów będzie aktywnym miesiącem: Lista, Przegląd i crm brief wrócą do niego.',
        );
    });
});

describe('month mark', () => {
    test('a short uppercase month', () => {
        expect(monthMark('2026-09', 'pl-PL')).toBe('WRZ');
        expect(monthMark('2026-10', 'pl-PL')).toBe('PAŹ');
    });
});
