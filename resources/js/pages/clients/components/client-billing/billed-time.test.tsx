import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../../../lang/pl.json';
import { billedTip, bothValues, fieldHours, parseHours, taskTip } from './billed-format';
import { afterTaskChange, readOnlyLine } from './billed-time-dialog';
import type { BilledMonth, BilledTaskRow, LedgerRow } from './types';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = i18next.t.bind(i18next);
const spaces = (text: string) => text.replace(/[\u00a0\u202f]/g, ' ');

const row = (period: string, extra: Partial<LedgerRow> = {}): LedgerRow => ({
    key: period,
    report_id: null,
    period,
    period_start: `${period}-01`,
    period_end: `${period}-28`,
    whole_month: true,
    ended: true,
    contracted: 24,
    used: 26,
    available: 13.7,
    opening: -10.3,
    forfeited: 0,
    closing: -12.3,
    change: -2,
    status: null,
    sent_on: null,
    settlement: 'open',
    active: true,
    before_baseline: false,
    closed_on: null,
    closure_id: null,
    reopen_refusal: null,
    reopen_blocker: null,
    billed: { real: 27.3, billed: 26, tasks: [], note: '1,5 h na poprawkę mojego błędu' },
    billed_editable: true,
    ...extra,
});

const month = (extra: Partial<BilledMonth> = {}): BilledMonth => ({
    client_id: 7,
    client: 'Pracownia Mebli',
    period: '2026-09',
    real: 25.6,
    billed: 26,
    tasks: [],
    note: null,
    used: 26,
    source: 'closure',
    has_retainer: true,
    opening: -8.3,
    available: 15.7,
    closing: -10.3,
    contracted: 24,
    closed: true,
    closed_on: '2026-10-05',
    closure_id: 3,
    report: { id: 9, status: 'sent', sent_on: '2026-10-03' },
    refusal: 'Otwórz wrzesień 2026 ponownie, żeby zmienić liczby.',
    refusal_code: 'period_settled',
    ...extra,
});

const task = (extra: Partial<BilledTaskRow> = {}): BilledTaskRow => ({
    task_id: 4,
    task: 'Poprawka RWD nagłówka',
    project: 'Strona WWW',
    real: 0.47,
    stated: 3,
    billed: 3,
    note: 'wycena ryczałtem',
    ...extra,
});

describe('billed time figures', () => {
    test('a month off its real time names the real time and the note, a month at its real time has no marker', () => {
        expect(spaces(billedTip(row('2026-10').billed!, 'pl-PL', t) ?? '')).toBe('Twoja liczba. Czas rzeczywisty 27,3 h. Notatka: 1,5 h na poprawkę mojego błędu');
        expect(billedTip({ real: 26, billed: 26, note: null }, 'pl-PL', t)).toBeNull();
    });

    test('a task with a stated figure names both values and its note, a task at its real time has no marker', () => {
        expect(spaces(taskTip(task(), 'pl-PL', t) ?? '')).toBe('Twoja liczba: 3,0 h. Czas rzeczywisty 0,47 h. Notatka: wycena ryczałtem');
        expect(taskTip(task({ stated: null, billed: 0.47, note: null }), 'pl-PL', t)).toBeNull();
    });

    test('a task figure moves the month total from the live task figures, and the balance follows from the available hours', () => {
        const month = { available: 11.7, tasks: [task(), task({ task_id: 5, stated: null, real: 13.5, billed: 13.5 })] };

        expect(afterTaskChange(month, task(), 4)).toEqual({ now: 16.5, total: 17.5, closing: -5.8 });
        expect(afterTaskChange(month, task(), null)).toEqual({ now: 16.5, total: 13.97, closing: -2.27 });
    });

    test('a preview adds whole minutes as the server does, not rounded hours', () => {
        const oneMinute = (id: number) => task({ task_id: id, stated: null, real: 0.02, billed: 0.02, note: null });

        expect(afterTaskChange({ available: 0, tasks: [oneMinute(1), oneMinute(2)] }, oneMinute(1), null).total).toBe(0.03);
    });

    test('typed figures take a comma and a real minus sign', () => {
        expect(parseHours('−12,3')).toBe(-12.3);
        expect(parseHours(' 26 ')).toBe(26);
        expect(parseHours('')).toBeNull();
        expect(Number.isNaN(parseHours('abc'))).toBe(true);
        expect(fieldHours(-12.3, 'pl-PL')).toBe('−12,3');
        expect(spaces(bothValues(row('2026-10').billed!, 'pl-PL'))).toBe('27,3 · 26,0 h');
    });

    test('a read-only month says what locks it', () => {
        expect(readOnlyLine(month(), 'pl-PL', t)).toBe(
            'Wrzesień jest zamknięty od 05.10, a raport wysłany 03.10, więc tych liczb nie zmienisz. Żeby je poprawić, otwórz miesiąc ponownie.',
        );
        expect(readOnlyLine(month({ closed: false, closed_on: null, closure_id: null, refusal_code: 'not_a_draft' }), 'pl-PL', t)).toBe(
            'Raport za wrzesień jest wysłany, więc tych liczb nie zmienisz. Żeby je poprawić, otwórz raport ponownie.',
        );
    });
});
