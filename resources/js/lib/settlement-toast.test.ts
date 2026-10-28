import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import pl from '../../../lang/pl.json';
import { settlementToast } from './settlement-toast';

await i18next.init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const t = ((key: string, options?: Record<string, unknown>) => i18next.t(key, options)) as Parameters<typeof settlementToast>[2];

describe('settlement toast', () => {
    test('a close reads as the frame: the month closed, the balance it carries', () => {
        expect(settlementToast({ action: 'closed', period: '2026-09', closing: -11.5, next: '2026-10', recalculate: null }, 'pl', t)).toEqual({
            title: 'Wrzesień zamknięty',
            description: 'Bilans −11,5 h przechodzi na październik.',
        });
    });

    test('hours keep one decimal and a second only when needed', () => {
        expect(settlementToast({ action: 'closed', period: '2026-09', closing: -16, next: '2026-10', recalculate: null }, 'pl', t).description).toBe(
            'Bilans −16,0 h przechodzi na październik.',
        );
        expect(settlementToast({ action: 'closed', period: '2026-09', closing: -0.25, next: '2026-10', recalculate: null }, 'pl', t).description).toBe(
            'Bilans −0,25 h przechodzi na październik.',
        );
    });

    test('a close or reopen that flagged a later draft asks for its recalculation', () => {
        expect(settlementToast({ action: 'closed', period: '2026-09', closing: 4, next: '2026-10', recalculate: '2026-10' }, 'pl', t).description).toBe(
            'Bilans +4,0 h przechodzi na październik. Przelicz raport za październik 2026.',
        );
        expect(settlementToast({ action: 'reopened', period: '2026-09', recalculate: null }, 'pl', t)).toEqual({
            title: 'Wrzesień znów otwarty',
            description: 'Znów jest aktywnym miesiącem.',
        });
    });
});
