import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';

import en from '../../../lang/en.json';
import pl from '../../../lang/pl.json';

const i18n = i18next.createInstance();
await i18n.init({
    lng: 'pl',
    fallbackLng: 'en',
    resources: { en: { translation: en }, pl: { translation: pl } },
    interpolation: { escapeValue: false },
});

describe('count strings', () => {
    test('the subtask count on a card takes the Polish form for each number', () => {
        const t = i18n.getFixedT('pl');
        expect(t('subtask_count', { count: 1 })).toBe('1 podzadanie');
        expect(t('subtask_count', { count: 2 })).toBe('2 podzadania');
        expect(t('subtask_count', { count: 5 })).toBe('5 podzadań');
    });
});

describe('action labels', () => {
    test('no string opens with a typed plus; actions are a verb or Nowy/Nowa/Nowe', () => {
        for (const strings of [en, pl] as Record<string, string>[]) {
            expect(Object.entries(strings).filter(([key, value]) => /^\+\s/.test(key) || /^\+\s/.test(value))).toEqual([]);
        }
    });

    test('Pokaż N więcej reads the same in every Polish form', () => {
        const t = i18n.getFixedT('pl');
        expect([1, 2, 5, 22].map((count) => t('Show {{count}} more', { count }))).toEqual([
            'Pokaż 1 więcej',
            'Pokaż 2 więcej',
            'Pokaż 5 więcej',
            'Pokaż 22 więcej',
        ]);
        expect(i18n.getFixedT('en')('Show {{count}} more', { count: 3 })).toBe('Show 3 more');
    });
});
