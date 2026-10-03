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
    test('the session count on the task page takes the Polish form for each number', () => {
        const t = i18n.getFixedT('pl');
        expect(t('session_count_summary', { count: 1, total: '2h' })).toBe('1 sesja · 2h');
        expect(t('session_count_summary', { count: 3, total: '2h' })).toBe('3 sesje · 2h');
        expect(t('session_count_summary', { count: 5, total: '2h' })).toBe('5 sesji · 2h');
        expect(t('session_count_summary', { count: 22, total: '2h' })).toBe('22 sesje · 2h');
    });

    test('the session count reads singular and plural in English', () => {
        const t = i18n.getFixedT('en');
        expect(t('session_count_summary', { count: 1, total: '2h' })).toBe('1 session · 2h');
        expect(t('session_count_summary', { count: 4, total: '2h' })).toBe('4 sessions · 2h');
    });

    test('the subtask count on a card takes the Polish form for each number', () => {
        const t = i18n.getFixedT('pl');
        expect(t('subtask_count', { count: 1 })).toBe('1 podzadanie');
        expect(t('subtask_count', { count: 2 })).toBe('2 podzadania');
        expect(t('subtask_count', { count: 5 })).toBe('5 podzadań');
    });
});
