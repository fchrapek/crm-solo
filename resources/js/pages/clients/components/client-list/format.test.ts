import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import { monthName } from '@/lib/dates';
import pl from '../../../../../../lang/pl.json';
import { balanceOf, hoursLine, hoursNumber, rangeOf } from './format';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = ((key: string, options?: Record<string, unknown>) => i18next.t(key, options)) as Parameters<typeof hoursLine>[2];
const retainer = { period: '2026-10', behind: false, has_retainer: true, used: 36.5, available: 20.5, remaining: -16, logged: 40 };
const hourly = { period: '2026-10', behind: false, has_retainer: false, used: 0, available: 0, remaining: 0, logged: 37.75 };

describe('Lista hours', () => {
    test('a retainer reads used against available, anything else reads every hour logged', () => {
        expect(hoursLine(retainer, 'pl-PL', t)).toBe('36,5 z 20,5 h');
        expect(hoursLine(hourly, 'pl-PL', t)).toBe('37,75 h');
    });

    test('an overrun balance is negative with a real minus, a surplus carries a plus, no retainer is a dot', () => {
        expect(balanceOf(retainer, 'pl-PL')).toEqual({ text: '−16,0 h', tone: 'over' });
        expect(balanceOf({ ...retainer, remaining: 5 }, 'pl-PL')).toEqual({ text: '+5,0 h', tone: 'under' });
        expect(balanceOf({ ...retainer, remaining: 0 }, 'pl-PL')).toEqual({ text: '0,0 h', tone: 'under' });
        expect(balanceOf(hourly, 'pl-PL')).toEqual({ text: '·', tone: 'none' });
    });

    test('numbers follow the locale', () => {
        expect(hoursNumber(9.5, 'en-GB')).toBe('9.5');
        expect(hoursNumber(9.5, 'pl-PL')).toBe('9,5');
    });

    test('the month head uses the standalone month name', () => {
        expect(monthName('2026-10', 'pl-PL')).toBe('październik');
        expect(monthName('2026-09', 'en-GB')).toBe('September');
    });

    test('the pagination range joins with an en dash the lang files cannot hold', () => {
        expect(i18next.t('{{range}} of {{total}}', { range: rangeOf(1, 10), total: 22 })).toBe('1\u201310 z 22');
    });
});
