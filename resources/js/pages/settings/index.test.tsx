import { describe, expect, test } from 'bun:test';
import i18next from 'i18next';
import { renderToStaticMarkup } from 'react-dom/server';
import { initReactI18next } from 'react-i18next';

import pl from '../../../../lang/pl.json';
import { AppearanceTab } from './components/appearance-tab';
import Settings, { settingsTabs } from './index';

await i18next.use(initReactI18next).init({ lng: 'pl', resources: { pl: { translation: pl } }, interpolation: { escapeValue: false } });

const context = { t: (key: string, options?: Record<string, unknown>) => i18next.t(key, options), url: '/settings', locale: 'pl-PL' };
const auth = (billing: boolean) => ({ user: { account: { features: { billing } } } }) as never;
const place = (tab: string | null, owner = true, billing = true) => Settings.place({ tab, is_owner: owner, auth: auth(billing) } as never, context);

describe('Ustawienia', () => {
    test('a member has Konto and Wygląd only, and an account with billing off has no e-mail tab', () => {
        expect(settingsTabs(false, true)).toEqual(['konto', 'wyglad']);
        expect(settingsTabs(true, false)).not.toContain('email');
        expect(settingsTabs(true, true)).toHaveLength(8);
        expect(place('konto', false).pills?.map((pill) => pill.label)).toEqual(['Konto', 'Wygląd']);
    });

    test('the token is the tab and the tabs are the pills, one of them current', () => {
        const { tokens, pills, pillsFrom } = place('integracje');

        expect(tokens).toEqual([{ label: 'Integracje' }]);
        expect(pills?.filter((pill) => pill.active).map((pill) => pill.label)).toEqual(['Integracje']);
        expect(pillsFrom).toBeUndefined();
    });

    test('/settings has no token; its pills stand from 768 px with Konto current, a phone lists the sections itself', () => {
        const { tokens, pills, pillsFrom } = place(null);

        expect(tokens).toEqual([]);
        expect(pills?.find((pill) => pill.active)?.label).toBe('Konto');
        expect(pillsFrom).toBe('wide');
    });

    test('Wygląd offers the language and the mode, and no accent', () => {
        const html = renderToStaticMarkup(<AppearanceTab />);

        expect(html).toContain('aria-label="Język"');
        expect(html).toContain('aria-label="Tryb"');
        expect(html).not.toContain('Akcent');
    });
});
