import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { SettlementLine } from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const line = (state: 'ready' | 'closed' | 'blocked', extra: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <SettlementLine clientId={1} settlement={{ state, period: '2026-09', active_period: '2026-09', closed_on: null, closing: null, ...extra }} />,
    )
        .replace(/<[^>]+>/g, ' ')
        .replace(/[\u00a0\u202f]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

describe('month-close settlement line', () => {
    test('the month being settled can close and says what carries over', () => {
        expect(line('ready', { closing: -11.5 })).toBe(
            'Zamknij wrzesień zamraża bank godzin: bilans −11,5 h przechodzi na październik Zamknij miesiąc',
        );
    });

    test('a closed month shows its closing date and no button', () => {
        expect(line('closed', { closed_on: '2026-10-05' })).toBe('Zamknij wrzesień zamraża bank godzin za ten miesiąc zamknięty 05.10');
    });

    test('a later month names the one to close first', () => {
        expect(line('blocked', { period: '2026-10', active_period: '2026-09' })).toBe(
            'Zamknij październik zamraża bank godzin za ten miesiąc najpierw zamknij wrzesień',
        );
    });
});
